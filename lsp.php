#!/usr/bin/env php
<?php

$opts = getopt("a::c::Cfhi:l::", ['autoload::', 'check-cmds::', 'check-only', 'full-sync', 'help', 'include-path:', 'log::']);
$default_checkcmds = 'php -nl,phplint.php -f';
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

		@['result' => $result, 'error' => $error] = match($req->method) {
			'initialize' => [
				'result' => [
					'capabilities' => [
						'positionEncoding'              => 'utf-8',
						'textDocumentSync'              => (isset($opts['f']) || isset($opts['full-sync'])) ? 1 : 2,  # 1=Full, 2=Incremental
						'implementationProvider'        => $allfeatures,
						'documentSymbolProvider'        => $allfeatures,
						'hoverProvider'                 => $allfeatures,
						'completionProvider'            => $allfeatures ? ['resolveProvider' => false, 'triggerCharacters' => ['::']] : null,
						'documentHighlightProvider'     => $allfeatures,
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

			'textDocument/implementation', 'textDocument/hover' => [
				'result' => symbol($document, $uri, $identifier),
			],

			'textDocument/completion' => [
				'result' => $req->params->context->triggerKind == 1
				? (count($completions = array_values(array_filter(array_merge(
					array_map(fn($v) => completion($identifier, $v['name']), symbols($document)),
					array_map(fn($v) => completion($identifier, $v), get_defined_functions()['internal'])
				)))) < 30 ? $completions : [])
				: ($req->params->context->triggerKind == 2 && $req->params->context->triggerCharacter == '::' && ($class = reflectionclass(rtrim($identifier, ':')))
					? array_values(array_filter(array_map(fn($v) => completion($identifier, "$class->name::$v->name"),
						$class->getMethods(ReflectionMethod::IS_STATIC)
					)))
					: []
				),
			],

			'textDocument/documentHighlight' => [
				'result' => array_map(fn($v) => ['range' => $v['range'], 'kind' => 1], symbols($document, $identifier, $offset)),	# Kind 1=Text
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

			case ord('{'):
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

	try { $reflection = new ReflectionMethod($identifier);   } catch (Exception) {}
	try { $reflection = new ReflectionFunction($identifier); } catch (Exception) {}

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
				$reflection->getParameters()
			)) .
			') : ' . ($reflection->getReturnType() ?: '?') .
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

				foreach (explode("\n", $checkoutput) as $line)
				{
					# PHP Parse error:  syntax error, unexpected token "%", expecting end of file in Standard input code on line 3
					# t.php:28 $d used only once: $d = 42;
					if (preg_match('/^[^:]+:\s+(?<message>.*) in Standard input code on line (?<line>\d+)/', $line, $checkmatches) ||
					    preg_match('/^\S+:(?<line>\d+):\d+:?\s+(?<message>.*)/', $line, $checkmatches))
					{
						['line' => $checkline, 'message' => $checkmessage] = $checkmatches;
						$diagnostics[] = [
							'range'   => ['start' => ['line' => $checkline - 1, 'character' => 0], 'end' => ['line' => $checkline - 1, 'character' => 0]],
							'severity' => preg_match('/error/', $checkmessage) ? 1 : 2,	# 1=Error, 2=Warning
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

		fputs($sockets[0], "$response\n");
		fclose($sockets[0]);
		exit(0);
	}
}

function reflectionfunction($name)
{
	try { $reflection = new ReflectionFunction($name); } catch (Exception) {}
	try { $reflection = new ReflectionMethod($name); } catch (Exception) {}
	return $reflection ?? null;
}

function reflectionclass($name)
{
	try { $reflection = new ReflectionClass($name); } catch (Exception) {}
	return $reflection ?? null;
}

function completion($identifier, $name, $kind = 2)
{
	$func = preg_replace('/^\w+::/', '', $name);

	return str_starts_with($name, $identifier) ? array_filter([
		'kind' => $kind,
		'label' => $func,
		'insertTextFormat' => 2,
		'insertText' => $func . '(${1})',
		'documentation' => documentation(reflectionfunction($name))['contents'],
	]) : [];
}
