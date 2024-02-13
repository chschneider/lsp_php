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

		@['result' => $result, 'error' => $error] = match($req->method) {
			'initialize' => [
				'result' => [
					'capabilities' => [
						'positionEncoding'       => 'utf-8',
						'textDocumentSync'       => (isset($opts['f']) || isset($opts['full-sync'])) ? 1 : 2,  # 1=Full, 2=Incremental
						'implementationProvider' => $allfeatures,
						'documentSymbolProvider' => $allfeatures,
						'hoverProvider'          => $allfeatures,
						'completionProvider'     => $allfeatures ? ['resolveProvider' => false, 'triggerCharacters' => ['::']] : null,
					],
				],
			],

			'textDocument/didOpen' => [
				'void' => $documents[$req->params->textDocument->uri] = $req->params->textDocument->text,
			],

			'textDocument/didChange' => [
				'void' => $documents[$req->params->textDocument->uri] = array_reduce(
					$req->params->contentChanges,
					fn($d, $v) => $v->range
						? (substr($d, 0, offset($d, $v->range->start)) . $v->text . substr($d, offset($d, $v->range->end)))
						: $v->text,
					$documents[$req->params->textDocument->uri]
				),
			],

			'textDocument/didClose' => [
				'void' => $documents[$req->params->textDocument->uri] = null,
			],

			'textDocument/documentSymbol' => [
				'result' => symbols($documents[$req->params->textDocument->uri]),
			],

			'textDocument/implementation', 'textDocument/hover' => [
				'result' => symbol($documents[$req->params->textDocument->uri], $req),
			],

			'textDocument/completion' => [
				'result' => $req->params->context->triggerKind == 1
				? array_merge(
					array_map(fn($v) => ['kind' => 3, 'label' => $v['name'], 'insertText' => $v['name'] . '('], symbols($documents[$req->params->textDocument->uri])),
					array_map(fn($v) => ['kind' => 3, 'label' => $v, 'insertText' => $v . '(', 'documentation' => documentation(reflectionfunction($v))['contents']], get_defined_functions()['internal'])
				)
				: ($req->params->context->triggerKind == 2 && $req->params->context->triggerCharacter == '::' && ($class = reflectionclass(rtrim(identifier($documents[$req->params->textDocument->uri], $req->params->position), ':')))
					? array_map(fn($v) => ['kind' => 2, 'label' => $v->name, 'insertText' => $v->name . '(', 'documentation' => documentation($v)['contents']],
						$class->getMethods(ReflectionMethod::IS_STATIC)
					)
					: null
				),
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

# Helper function
function symbols($document)
{
	# To generate symbol table
	$symbols = [];
	$line = $col = 0;
	$functiondef = false;

	$result = array_values(array_filter(array_map(function($token) use (&$functiondef, &$line, &$col, $symbols) {
		if ($line != intval($token[2]) - 1)
			[$line, $col] = [intval($token[2]) - 1, 0];

		switch ($token[0])
		{
			case T_WHITESPACE: case T_DOC_COMMENT: case T_COMMENT:
				break;

			case T_FUNCTION:
				$functiondef = true;
				break;

			case T_STRING:
				if ($functiondef)
				{
					return [
						'name' => $token[1],
						'kind' => 12,  # Function
						'range' => [
							'start' => ['line' => $line, 'character' => $col],
							'end'   => ['line' => $line, 'character' => $col + strlen($token[1])],
						],
						'selectionRange' => [
							'start' => ['line' => $line, 'character' => $col],
							'end'   => ['line' => $line, 'character' => $col + strlen($token[1])],
						],
					];
				}

			default:
				$functiondef = false;
				break;
		}

		$col += strlen($token[1]);
		return null;
	}, token_get_all((string)$document))));

	return $result;
}

function identifier($document, $position)
{
	$start = $end = offset($document, $position);

	while ($start > 0 && (IntlChar::chr($document[$start - 1]) === null || preg_match('/[\w:]/u', $document[$start - 1])))
		$start--;

	while ($end < strlen($document) && (IntlChar::chr($document[$end]) === null || preg_match('/[\w:]/u', $document[$end])))
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

function symbol($document, $req)
{
	$identifier = identifier($document, $req->params->position);
	$name = preg_replace('/^\w+::/', '', $identifier);

	try { $reflection = new ReflectionMethod($identifier);   } catch (Exception) {}
	try { $reflection = new ReflectionFunction($identifier); } catch (Exception) {}

	if (!($symbol = documentation($reflection))['contents'])
	{
		$uri = $req->params->textDocument->uri;
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
	return $reflection ?? null;
}

function reflectionclass($name)
{
	try { $reflection = new ReflectionClass($name); } catch (Exception) {}
	return $reflection ?? null;
}
