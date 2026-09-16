# SVG Sanitizer

Core Blueprint includes the upstream `darylldoyle/svg-sanitizer` library, version 0.22.0, solely as the SVG sanitization engine used by the optional Media Formats module.

- Upstream project: https://github.com/darylldoyle/svg-sanitizer
- Upstream version: 0.22.0
- License: GNU General Public License v2.0 or later
- Upstream commit used for this vendored source: `0afa95ea74be155a7bcd6c6fb60c276c39984500`

The upstream source is bundled locally so Core Blueprint does not require an external service or another WordPress plugin. For dependency isolation, Core Blueprint prefixes the PHP namespace from `enshrined\\svgSanitize` to `CB\\Core\\MediaFormats\\Vendor\\SvgSanitize`. No sanitizer behavior is intentionally changed by this namespace prefix.

Local security patch (2026-09-16): `isHrefSafeValue()` also honors
`removeRemoteReferences(true)` for direct HTTP(S), protocol-relative and
root-relative hrefs. This applies to both `href` and `xlink:href`; local fragment
references and the existing allowed raster data URIs remain supported. The
upstream version above identifies the vendored baseline, not an unmodified copy.

The original upstream license is also preserved at:

`src/MediaFormats/lib/svg-sanitizer/LICENSE`
