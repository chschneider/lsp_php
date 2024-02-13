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
  -c=CMDS, --check-cmds=CMDS    Run these syntax check / lint commands on code
  -C,       --check-only        Only run check-cmds, no other functionality
  -f,      --full-sync          Use full instead of incremental sync
  -h,      --help               Show usage
  -i=PATH, --include-path=PATH  Prepend this path to the include path
  -l=FILE, --log=FILE           Enable logging to file FILE (default: ~/.cache/helix/lsp_php.log)
```

## Features
Currently is supports the following language features:
| Command                         | Description                                                           | helix keymap        |
|:--------------------------------|:----------------------------------------------------------------------|:--------------------|
| textDocument/documentSymbol     | List of functions/methods defined in current document                 | `Space + s`         |
| textDocument/hover              | Show function definition, i.e. parameters, return type and doccomment | `Space + k`         |
| textDocument/implementation     | Goto function implementation                                          | `gi`                |
| textDocument/publishDiagnostics | Run syntax check/lint on text changes and display errors/warnings     | `Space + d`         |
| textDocument/completion         | Show completion for PHP builtin functions or static method in classes | `ctrl-x` or `foo::` |
