# Tree-sitter Twig Support

Evolver uses [tree-sitter-twig](https://github.com/gbprod/tree-sitter-twig) to parse Twig templates and extract semantic information. This is useful for both Drupal (SDC components, standard templates) and general Symfony projects.

## Installation & Compilation

Since `tree-sitter-twig` is not available as a pre-built package in many distributions, it must be compiled from source.

### Docker (Automatic)
The project's [Dockerfile](/home/andy/www/drupal/DrupalEvolver/Dockerfile) builds Twig support from the maintained upstream repository at <https://github.com/gbprod/tree-sitter-twig> during the `ts-source` stage. The compiler toolchain stays in that build stage only; the final runtime image keeps just the compiled grammar artifacts.

The final image installs the grammar beside the other system grammars:

- `/usr/lib/libtree-sitter-twig.so`

### Manual Compilation
Requirements: `gcc` or `clang`.

1. Clone the repository: `git clone https://github.com/gbprod/tree-sitter-twig`
2. Run the compiler:
   ```bash
   gcc -O3 -shared -fPIC -Isrc src/parser.c -o libtree-sitter-twig.so
   ```
3. Move the `.so` file to your library path (e.g., `/usr/lib` or `/usr/local/lib`).

## Extraction Capabilities

The `DrupalEvolver\Indexer\Extractor\TwigExtractor` extracts the following symbols:

- **Tags:**
    - `twig_extends`: Base templates.
    - `twig_include`: Included templates.
    - `twig_embed`: Embedded templates.
    - `twig_component`: Standard Twig component calls.
    - `sdc_include`: SDC-specific `include` (Drupal).
    - `sdc_embed`: SDC-specific `embed` (Drupal).
    - `sdc_call`: SDC-specific `component` call (Drupal).
    - `twig_tag`: Other generic tags.
- **Variables:**
    - `twig_variable`: Variables used in output directives `{{ ... }}`.
- **Functions:**
    - `twig_function`: Twig function calls.
    - `sdc_function`: Drupal-specific `component()` function calls.

## Example: Parsing Project Templates

You can test the parsing by running the following script:

```bash
make ev -- php scripts/parse-own-templates.php
```

This will output all extracted symbols from the `templates/` directory, showing relationships between layouts, includes, and variables.

## Usage in Symfony

While this tool is optimized for Drupal, the `TwigExtractor` and the underlying Tree-sitter grammar are generic. You can use it to:
1. Map template inheritance chains.
2. Find all usages of a specific variable across a project.
3. Detect deprecated Twig functions or filters by diffing grammar outputs.
