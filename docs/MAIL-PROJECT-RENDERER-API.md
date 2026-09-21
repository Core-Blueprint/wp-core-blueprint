# Mail Project Renderer API — Core API v1

Core Blueprint Base exposes a storage- and transport-independent renderer for extensions that own their own Mail Design projects.

Use this contract when an extension owns the project JSON and subject, but wants the same validated HTML and binding semantics as the Base Mail Designer.

## Public contract

`CB\Core\Mail\ProjectRenderer` accepts:

- a Design Foundation `mail-template` project;
- a subject string;
- one resolved binding map;
- optional render options such as `editor_markers` for preview surfaces.

It returns:

```text
[
    subject => rendered subject,
    html    => rendered email-safe HTML
]
```

Example:

```php
use CB\Core\Mail\ProjectRenderer;

$rendered = ( new ProjectRenderer() )->render(
    $project,
    'Hello {{contact.first_name}}',
    [
        'contact.first_name' => 'Jane',
        'newsletter.unsubscribe_url' => $unsubscribe_url,
    ]
);
```

Subject and HTML use the same canonical `{{ binding.key }}` interpolation contract. Unknown or null bindings resolve to an empty string. The renderer does not resolve domain bindings itself.

## Ownership boundary

`ProjectRenderer` deliberately does not own:

- project/template persistence;
- campaign or recipient state;
- binding resolution or authorization;
- sender identity selection;
- transport credentials;
- delivery, retry or queue behavior;
- delivery or campaign reporting.

The caller owns those workflow concerns. Base only validates and renders the supplied project and subject.

For registered Base Mail Designer templates, `CB\Core\Mail\Designer\Renderer` remains the convenience layer. It resolves the registered template and Base binding providers, then delegates the actual project rendering to `ProjectRenderer`.

## Failure semantics

Invalid Mail projects continue to throw the canonical Design Foundation validation exception from `HtmlRenderer`. Public workflow consumers should catch that exception at their own commit/worker boundary and fail closed rather than silently sending fallback content.

The Base Mail Designer convenience layer keeps its existing behavior of catching render failures and returning `null`, allowing the canonical WordPress/domain message to remain unchanged.

## Delivery

Rendering is separate from sending. Extensions that send the rendered output must use `CB\Core\Mail\Sender::send()` with a registered sender identity rather than constructing their own SMTP or provider transport.
