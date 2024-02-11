# lsp_php
Language Server Protocol server for PHP written in PHP

This LSP was tested with the [helix](https://helix-editor.com) editor.
It is written in PHP and uses Reflection to get info about the PHP code.

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
  -f,      --full-sync          Use full instead of incremental sync
  -h,      --help               Show usage
  -i=PATH, --include-path=PATH  Prepend this path to the include path
  -l=FILE, --log=FILE           Enable logging to file FILE
```
