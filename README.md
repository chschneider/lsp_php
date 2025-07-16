# lsp_php
A Language Server Protocol server for PHP written in PHP.

This LSP is written in PHP 8.x and uses Reflection to get info about the PHP code.\
It is a partial implementation of the [LSP specification 3.17](https://microsoft.github.io/language-server-protocol/specifications/lsp/3.17/specification/).
It was tested with the [helix](https://helix-editor.com) editor.

## Configuration
Example configration for helix in .config/helix/languages.toml
```toml
[language-server.php]
  command = "lsp.php"
  args = ['-l', '-iphpinclude']

[[language]]
  language-servers = ["php"]
```

## Usage
```
Usage: lsp.php [OPTIONS]
Options:
  -a=SUFFIX, --autoload=SUFFIX    Autoload from include_path with SUFFIX, [.php,.inc]
  -c=CMDS,   --check-cmds=CMDS    Run syntax check/lint CMDS on code [php -nl,phplint.php -f]
  -C,        --check-only         Only run check-cmds, no other functionality
  -f,        --full-sync          Use full instead of incremental sync
  -h,        --help               Show usage
  -i=PATH,   --include-path=PATH  Prepend PATH to the include path [phpinclude]
  -l=FILE,   --log=FILE           Enable logging to FILE, [~/.cache/helix/lsp_php.log]
```

## Features
Currently is supports the following language features:
| Command                         | Description                                                           | helix keymap        |
|:--------------------------------|:----------------------------------------------------------------------|:--------------------|
| textDocument/definition         | Go to function definition (same as go to implementation)              | `gd`                |
| textDocument/implementation     | Go to function implementation (same as go to definition)              | `gi`                |
| textDocument/references         | Go to static function reference, currently only works with git        | `gr`                |
| textDocument/completion         | Show completion for PHP builtin functions or static method in classes | `ctrl-x` or `foo::` |
| textDocument/codeAction         | Perform a code action on a selection, currently `blockify`            | `Space + a`         |
| textDocument/publishDiagnostics | Run syntax check/lint on text changes and display errors/warnings     | `Space + d`         |
| textDocument/hover              | Show function definition, i.e. parameters, return type and doccomment | `Space + k`         |
| textDocument/documentSymbol     | List of functions/methods defined in current document                 | `Space + s`         |
| textDocument/documentColor      | Show inline preview of colors (#rrggbb, rgba(), etc.)                 |                     |

## How to use it to check other languages
```toml
[language-server.javascript]
  command = "lsp.php"
  args = ['--log', '--check-cmds=jshint --reporter=unix -', '--check-only']

[[language]]
  name = "javascript"
  scope = "source.js"
  file-types = ["js", "mjs"]
  language-servers = ["javascript"]

[language-server.bash]
  command = "lsp.php"
  args = ['--log', '--check-cmds=shellcheck -f json1 -x -', '--check-only']

[[language]]
  name = "bash"
  scope = "source.sh"
  file-types = ["sh"]
  language-servers = ["bash"]
```
