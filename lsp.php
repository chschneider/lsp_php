#!/usr/bin/env php
<?php

$opts = getopt("a::c::Cfhi:l::", ['autoload::', 'check-cmds::', 'check-only', 'full-sync', 'help', 'include-path:', 'log::']);
$default_checkcmds = 'php -nl,phplint.php -fs';
$default_suffix    = '.php,.inc';
$default_include   = 'phpinclude';
$default_log       = '~/.cache/helix/lsp_php.log';

if (isset($opts['h']) || isset($opts['help']))
{
	fputs(STDERR, <<<USAGE
Usage: $argv[0] [OPTIONS]
Options:
  -a=SUFFIX, --autoload=SUFFIX    Autoload from include_path with SUFFIX, [$default_suffix]
  -c=CMDS,   --check-cmds=CMDS    Run syntax check/lint CMDS on code [$default_checkcmds]
  -C,        --check-only         Only run check-cmds, no other functionality
  -f,        --full-sync          Use full instead of incremental sync
  -h,        --help               Show usage
  -i=PATH,   --include-path=PATH  Prepend PATH to the include path [$default_include]
  -l=FILE,   --log=FILE           Enable logging to FILE, [$default_log]

USAGE);
	exit(1);
}

$include_path = ($opts['i'] ?? $opts['include-path'] ?? null) ?: $default_include;
ini_set('include_path', "$include_path:" . ini_get('include_path'));

if (!is_null($suffix = $opts['a'] ?? $opts['autoload'] ?? null))
{
	spl_autoload_extensions($suffix ?: $default_suffix);
	spl_autoload_register();
}

$checkcmds = $opts['c'] ?? $opts['check-cmds'] ?? null ?: $default_checkcmds;
$allfeatures = $opts['C'] ?? $opts['check-only'] ?? true;

$log = fopen(($logfile = $opts['l'] ?? $opts['log'] ?? '/dev/null') ? $logfile : str_replace('~', getenv('HOME'), $default_log), 'a');
$state = 'new';
$len = null;
$documents = [];

while (!feof(STDIN))
{
	$line = null;
	do {
		$read = [STDIN];
		$write = $except = null;
		if (stream_select($read, $write, $except, 0, 500_000))
		{
			$line = fgets(STDIN, $len);
			fputs($log, "$state($len): $line");
		}
		else
		{
			# fputs($log, "$state: timeout\n");
			check(null, null, null);
		}
	} while(!feof(STDIN) && !$line);

	[$state, $len, $req] = match($state) {
		'new'  => ['nl',   preg_match('/Content-Length: (\d+)/', $line, $m) ? ($m[1] + 1) : null, null],
		'nl'   => ['read', $len, null],
		'read' => ['new',  null, json_decode($line)],
	};

	if ($req)
	{
		unset($result, $error);
		$document = isset($req->params->textDocument) ? ($documents[$req->params->textDocument->uri] ?? null) : null;
		$uri = isset($req->params->textDocument->uri) ? ($req->params->textDocument->uri)                     : null;
		$identifier = isset($req->params->position)   ? identifier($document, $req->params->position)         : null;
		$offset = isset($req->params->position)       ? offset($document, $req->params->position)             : null;
		$documentclass= isset($document) && preg_match('/^\s*class\s+(\w+)/m', $document, $m) ? preg_replace('/_tools$/', '(_tools)?', $m[1]) : '';
		$fqidentifier = !$documentclass || !$identifier || preg_match('/::/', $identifier) ? $identifier : "$documentclass::$identifier";

		@['result' => $result, 'error' => $error] = match($req->method) {
			'initialize' => [
				'result' => [
					'capabilities' => [
						'positionEncoding'              => 'utf-8',
						'textDocumentSync'              => (isset($opts['f']) || isset($opts['full-sync'])) ? 1 : 2,  # 1=Full, 2=Incremental
						'definitionProvider'            => $allfeatures,
						'implementationProvider'        => $allfeatures,
						'referencesProvider'		=> $allfeatures,
						'documentSymbolProvider'        => $allfeatures,
						'hoverProvider'                 => $allfeatures,
						'completionProvider'            => $allfeatures ? ['resolveProvider' => false, 'triggerCharacters' => ['::']] : null,
						'documentHighlightProvider'     => $allfeatures,
						'codeActionProvider'            => $allfeatures,
					],
				],
			],

			'textDocument/didOpen' => [
				'void' => $documents[$uri] = $document = $req->params->textDocument->text,
			],

			'textDocument/didChange' => [
				'void' => $documents[$uri] = $document = array_reduce(
					$req->params->contentChanges,
					fn($d, $v) => $v->range
						? (substr($d, 0, offset($d, $v->range->start)) . $v->text . substr($d, offset($d, $v->range->end)))
						: $v->text,
					$document
				),
			],

			'textDocument/didClose' => [
				'void' => $documents[$uri] = $document = null,
			],

			'textDocument/documentSymbol' => [
				'result' => array_map(fn($v) => ['children' => []] + $v, symbols($document)),
			],

			'textDocument/definition', 'textDocument/implementation', 'textDocument/hover' => [
				'result' => symbol($document, $uri, $identifier),
			],

			'textDocument/references' => [
				'result' => array_filter(array_map(
					fn($v) => preg_match('/^([^:]+):\s*(\d+):\s*(\d+)/', $v, $m) ? [
						'uri' => 'file://' . realpath($m[1]),
						'range' => [
							'start' => ['line' => $m[2] - 1, 'character' => $m[3] - 1],
							'end'   => ['line' => $m[2] - 1, 'character' => $m[3] - 1],
						],
					] : null,
					explode("\n", shell_exec('(git grep --line-number --column --perl-regexp ' . escapeshellarg("\b$fqidentifier\\(") . '; git grep --line-number --column --perl-regexp ' . escapeshellarg(preg_replace("/^(" . preg_quote($documentclass, '/') . ")::/", "\b($1|self|static)::", "$fqidentifier\\(")) . ' | grep --perl-regexp ' . escapeshellarg(preg_replace('/::.*/', '\b', "\b$fqidentifier\b")) . ') | sort -u')),
				)),
			],

			'textDocument/completion' => [
				'result' => str_contains($identifier, '::') && ($class = reflection(rtrim($identifier, ':')))
				? array_values(array_filter(array_map(fn($v) => completion($identifier, "$class->name::$v->name", 2),	# 2=Method
					method_exists($class, 'getMethods') ? $class->getMethods(ReflectionMethod::IS_STATIC) : []
				)))
				: (count($completions = array_values(array_filter(array_merge(
					array_map(fn($v) => completion($identifier, $v['name']), symbols($document)),
					array_map(fn($v) => completion($identifier, $v), get_defined_functions()['internal'])
				)))) < 60 ? $completions : [])
			],

			'textDocument/documentHighlight' => [
				'result' => array_map(fn($v) => ['range' => $v['range'], 'kind' => 1], symbols($document, $identifier, $offset)),	# Kind 1=Text
			],

			'textDocument/codeAction' => [
				'result' => [
					blockify($document, $uri, $req->params->range),
				],
			],

			'shutdown', 'exit' => [],

			default => [
				'error' => [
					'code'    => -32601,  # MethodNotFound
					'message' => 'Method not found',
				],
			],
		};

		$response = @json_encode([
			'id'     => $req->id,
			'result' => $result,
			'error'  => $error,
		]);

		if (isset($req->id))
		{
			fputs(STDOUT, "Content-Length: " . strlen($response) . "\r\n\r\n" . $response);
			# fputs($log, "Content-Length: " . strlen($response) . "\r\n\r\n" . $response);
			fflush(STDOUT);
		}
		# else
		#   fputs($log, "\n\nDOC '$document'\n\n");

		# Run syntax checkers and lints on changed/opened documents
		if ($req->method == 'textDocument/didOpen' || $req->method == 'textDocument/didChange')
			check($documents, $req->params->textDocument->uri, $checkcmds);

		if ($req->method == 'exit')
			exit(0);
	}
}

# Helper functions
function symbols($document, $identifier = null, $offset = null)
{
	# To generate symbol table
	$symbols = [];
	$funcdef = false;
	$funcbody = false;
	$funcname = null;
	$level = -42;

	foreach (PhpToken::tokenize((string)$document) as $token)
	{
		[$line, $col] = position($document, $token->pos);

		if ($token->isIgnorable())
			continue;

		switch ($token->id)
		{
			case T_FUNCTION:
				if (!$funcbody)	# Anonymous function inside function?
				{
					$funcdef = true;
					$funcname = null;
					$funcstart = ['line' => $line, 'character' => $col];
					$funcchildren = [];	# Children symbols of this function, e.g. variables
					$level = 0;
				}
				break;

			case ord('{'): case T_CURLY_OPEN: case T_DOLLAR_OPEN_CURLY_BRACES:
				if (!$level++)
					$funcbody = true;
				break;

			case ord('}'):
				if (!--$level)
				{
					$funcbody = false;
					$funcend = ['line' => $line, 'character' => $col + 1];
					$children = array_merge(...array_values($funcchildren));
					$symbols[] = [
						'name' => $funcname,
						'kind' => 12,  # 12=Function
						'range' => [
							'start' => $funcstart,
							'end'   => $funcend,
						],
						'selectionRange' => [
							'start' => $funcstart,
							'end'   => $funcstart,
						],
						'children' => $children,
					];

					if ($identifier && offset($document, $funcstart) <= $offset && offset($document, $funcend) > $offset)
						$identifiers = array_values(array_filter($children, fn($v) => $v['name'] == $identifier));

					$funcbody = false;
					$level = -42;
				}
				break;
			
			case T_STRING:
				if ($funcdef)
					$funcname ??= $token->text;
				break;

			case T_VARIABLE:
				if ($funcdef || $funcbody)
				{
					$funcchildren[$token->text][] = [
						'name' => $token->text,
						'kind' => 13,  # 13=Variable
						'range' => [
							'start' => ['line' => $line, 'character' => $col],
							'end'   => ['line' => $line, 'character' => $col + strlen($token->text)],
						],
						'selectionRange' => [
							'start' => ['line' => $line, 'character' => $col],
							'end'   => ['line' => $line, 'character' => $col + strlen($token->text)],
						],
					];
				}
				break;
		}
	}

	return $identifiers ?? $symbols;
}

function identifier($document, $position)
{
	$start = $end = offset($document, $position);

	while ($start > 0 && (IntlChar::chr($document[$start - 1]) === null || preg_match('/[$\w:]/u', $document[$start - 1])))
		$start--;

	while ($end < strlen($document) && (IntlChar::chr($document[$end]) === null || preg_match('/[$\w:]/u', $document[$end])))
		$end++;

	return substr($document, $start, $end - $start);
}

function offset($document, $position)
{
	['line' => $line, 'character' => $character] = (array)$position;

	for ($start = 0; $line > 0 && $start !== false; $line--)
		$start = strpos($document, "\n", $start) + 1;  # Byte offsets!
	$start = $start + $character;

	return $start;
}

function position($document, $offset)
{
	$lines = explode("\n", substr($document, 0, $offset));
	$line = count($lines) - 1;
	return [$line, strlen($lines[$line])];
}

function symbol($document, $uri, $identifier)
{
	$name = preg_replace('/^\w+::/', '', $identifier);
	$reflection = reflection($identifier);

	if (!($symbol = documentation($reflection))['contents'])
	{
		$range =  array_values(array_filter(symbols($document), fn($v) => $name === $v['name']))[0]['range'];
		$contents = explode("\n", $document)[$range['start']['line']];  # Whole line of function definition
		$symbol = [
			'uri' => $uri,
			'range' => $range,
			'contents' => $contents,
		];
	}

	return $symbol;
}

function documentation($reflection)
{
	if ($reflection)
	{
		if ($filename = $reflection->getFileName())
		{
			$uri = "file://$filename";
			$range =  ['start' => ['line' => $reflection->getStartLine() - 1, 'character' => 0], 'end' => ['line' => $reflection->getStartLine(), 'character' => 0]];
		}

		$isfunction = $reflection instanceof ReflectionFunctionAbstract;
		$doccomment = $reflection->getDocComment();
		$contents = trim('***' .
			($reflection->isStatic ? 'static ' : '') .
			# 'function ' .
			"$reflection->name(" .
			implode(', ', array_map(fn($v) =>
				($v->isVariadic() ? '...' : '') .
				($v->allowsNull ? '?' : '') .
				trim($v->getType() . ' $' . $v->getName()) .
				($v->isDefaultValueAvailable() ? (' = ' . ($v->isDefaultValueConstant() ? $v->getDefaultValueConstantName() : json_encode($v->getDefaultValue()))) : ''),
				$isfunction ? $reflection->getParameters() : [],
			)) .
			') : ' . ($isfunction ? ($reflection->getReturnType() ?: '?') : '') .
			"***\n\n" .
			preg_replace('!^[\s*/]*!m', '', $doccomment)
		);
	}

	return [
		'uri' => $uri ?? null,
		'range' => $range ?? null,
		'contents' => $contents ?? null,
	];
}

function check($documents, $uri, $checkcmds)
{
	static $child;
	static $sockets = null;
	static $queued;

	$sockets ??= stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

	if ($child)
	{
		# Main process
		if ($sockets[0])
		{
			fclose($sockets[0]);
			$sockets[0] = null;
		}

		if ($checkcmds)
			$queued = [$documents, $uri, $checkcmds];

		if (pcntl_waitpid($child, $dummy_status, WNOHANG))
		{
			$response = fgets($sockets[1]);
			fclose($sockets[1]);
			$child = $sockets = null;


			if ($queued)
			{
				check(...$queued);	# Start last check which queued up while other check was running
				$queued = null;
			}
			else if ($response)
				fputs(STDOUT, "Content-Length: " . strlen($response) . "\r\n\r\n" . $response);
		}
	}
	else if ($checkcmds && !($child = pcntl_fork()))
	{
		# Child process running check cmds
		fclose($sockets[1]);
		$diagnostics = [];

		foreach (explode(',', $checkcmds) as $checkcmd)
		{
			$pipes = [];
			if ($check = proc_open($checkcmd, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes))
			{
				fputs($pipes[0], $documents[$uri]);
				fclose($pipes[0]);

				$checkoutput = stream_get_contents($pipes[1]);
				fclose($pipes[1]);
				fclose($pipes[2]);

				$lines = @json_decode($checkoutput, true)['comments'] ?: explode("\n", $checkoutput);

				foreach ($lines as $line)
				{
					# PHP Parse error:  syntax error, unexpected token "%", expecting end of file in Standard input code on line 3
					# t.php:28 $d used only once: $d = 42;
					if (($checkmatches = is_array($line) ? $line : false) ||
					    preg_match('/^[^:]+:\s+(?<message>.*) in Standard input code on line (?<line>\d+)/', $line, $checkmatches) ||
					    preg_match('/^\S+:(?<line>\d+):\d+:?\s+(?<message>.*)/', $line, $checkmatches))
					{
						['line' => $checkline, 'message' => $checkmessage] = $checkmatches;
						$lines = explode("\n", $documents[$uri]);
						$identifier = is_array($line)	# JSON format?
							? substr($lines[$checkline - 1], $line['column'] - 1, $line['endColumn'] - $line['column'])
							: (preg_match('/"([^"]+)"|([$\w]+)[()]* (?:parameter not used|used only once|is deprecated)/', $checkmessage, $m) ? ($m[1] ?: $m[2]) : '');
						$startcol = $identifier ? strpos($lines[$checkline - 1], $identifier) : 0;
						$diagnostics[] = [
							'range'   => ['start' => ['line' => $checkline - 1, 'character' => $startcol], 'end' => ['line' => $checkline - 1, 'character' => $startcol + strlen($identifier)]],
							'severity' => preg_match('/error/', $checkmessage) ? 1 : 2,	# 1=Error, 2=Warning
							'tags' => preg_match('/deprecated/', $checkmessage) ? [2] : null,	# 1=Unnecessary, 2=Deprecated
							'message' => $checkmessage,
						];
					}
				}

				proc_close($check);

				$response = json_encode([
					'method' => 'textDocument/publishDiagnostics',
					'params' => [
						'uri' => $uri,
						'diagnostics' => $diagnostics,
					],
				]);

			}
		}

		@fputs($sockets[0], "$response\n");
		fclose($sockets[0]);
		exit(0);
	}
}

function reflection($name)
{
	try { $reflection = new ReflectionFunction($name); } catch (Exception) {}
	try { $reflection = new ReflectionMethod($name);   } catch (Exception) {}
	try { $reflection = new ReflectionClass($name);    } catch (Exception) {}
	return $reflection ?? null;
}

function completion($identifier, $name, $kind = 3)
{
	$func = preg_replace('/^\w+::/', '', $name);

	return str_starts_with($name, $identifier) ? array_filter([
		'kind' => $kind,
		'label' => $func,
		'insertTextFormat' => 2,
		'insertText' => $func . '(${1})',
		'documentation' => documentation(reflection($name))['contents'],
	]) : [];
}

# Code actions
function blockify($document, $uri, $range)
{
	$start = offset($document, ['line' => $range->start->line, 'character' => 0]);
	$end   = offset($document, ['line' => $range->end->line + ($range->end->character ? 1 : 0), 'character' => 0]) - 1;

	$block = substr($document, $start, $end - $start);
	preg_match('/^\h*/', $block, $match);
	$indent = $match[0];

	$prevlinestart = offset($document, ['line' => max($range->start->line - 1, 0), 'character' => 0]);
	$prevline = substr($document, $prevlinestart, $start - $prevlinestart);
	preg_match('/^(\h*)({?)/', $prevline, $match);
	[, $previndent, $prevbrace] = $match;

	[$braceindent, $blockindent] = (!$previndent || $prevbrace || $previndent == $indent) ? [$indent, "\t$indent"] : [$previndent, $indent];

	return [
		'title' => 'blockify',
		'edit'  => [
			'documentChanges' => [[
				'textDocument' => ['uri' => $uri],
				'edits' => [[
					'range' => [
						'start' => position($document, $start),
						'end'   => position($document, $end),
					],
					'newText' => "$braceindent{\n" . preg_replace('/^\s*/m', $blockindent, $block) . "\n$braceindent}",
				]],
			]],
		]
	];
}
