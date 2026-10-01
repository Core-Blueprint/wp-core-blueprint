# Dompdf PDF runtime

Core Blueprint bundles Dompdf and its runtime dependencies locally for PDF generation. No remote code is loaded at runtime.

## Bundled packages

- dompdf/dompdf 3.1.6
  - Upstream: https://github.com/dompdf/dompdf
  - Source reference: 6d4b4eb8500f7a786da8868ba463a71b725a4005
  - License: LGPL-2.1
- dompdf/php-font-lib 1.0.2
  - Upstream: https://github.com/dompdf/php-font-lib
  - Source reference: a6e9a688a2a80016ac080b97be73d3e10c444c9a
  - License: LGPL-2.1-or-later
- dompdf/php-svg-lib 1.0.2
  - Upstream: https://github.com/dompdf/php-svg-lib
  - Source reference: 8259ffb930817e72b1ff1caef5d226501f3dfeb1
  - License: LGPL-3.0-or-later
- masterminds/html5 2.10.0
  - Upstream: https://github.com/Masterminds/html5-php
  - Source reference: fcf91eb64359852f00d921887b219479b4f21251
  - License: MIT
- sabberworm/php-css-parser 8.9.0
  - Upstream: https://github.com/MyIntervals/PHP-CSS-Parser
  - Source reference: d8e916507b88e389e26d4ab03c904a082aa66bb9
  - License: MIT

The bundled Composer metadata and upstream license files remain inside the runtime tree under `src/PDF/lib/dompdf/vendor/`. The public Core Blueprint source repository contains the same vendored source and the canonical release tooling.
