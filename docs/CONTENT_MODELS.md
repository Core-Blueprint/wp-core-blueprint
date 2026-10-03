# Core Blueprint Content Models

Content Models is the optional Core Blueprint Base module for governed, WordPress-native content schema management. Definitions are Core Blueprint-managed; customer content remains in normal WordPress storage.

## Storage contract

- Custom post types use `register_post_type()`.
- Taxonomies use `register_taxonomy()`.
- Post fields use registered post meta.
- Term fields use registered term meta.
- User fields use registered user meta.
- Option Page fields use individually namespaced WordPress options.
- Image and File values are attachment IDs; Gallery values are ordered attachment-ID arrays.
- Relation values are native object IDs: one integer for single selection, an ordered integer array for multiple selection.
- Group values are associative arrays keyed by stable subfield names.
- Repeater values are ordered row arrays. Each persisted row carries a stable `_cb_row_id`.

The user-managed schema lives in the non-autoloaded `cb_core_content_models_schema` option and is versioned independently from customer content. Removing definitions or disabling Content Models never deletes customer posts, terms, meta or option values.

## Field Groups and locations

A Field Group can target one or more of these native contexts:

- Post Types
- Option Pages
- taxonomy term edit screens
- user/profile screens, optionally restricted by role

Storage/location matching is centralized so field definitions and value semantics do not fork by admin surface.

Field names/meta keys are immutable after creation. Field type changes require explicit confirmation; existing values are preserved and are not automatically migrated.

## Field types

### Basic

- Text
- Textarea
- WYSIWYG
- Number
- Email
- URL
- Date
- Time
- Date & Time
- Color
- True / False

### Choice

- Select
- Radio
- Checkbox

### Media

- Image
- File
- Gallery

### Structured

- Group
- Repeater

Structured fields are intentionally one level deep in schema v5. Group/Repeater subfields may use basic, choice, media and relation field types, but may not themselves be Group or Repeater fields. This keeps the v1 storage and validation contract deterministic while leaving room for a later explicit nested-schema version.

### Relations

- Post / Object Relation
- User Relation
- Taxonomy / Term Relation

Relations support single or multiple values and server-authorized async search through the shared Object Picker Foundation. The browser does not define the authorization scope; the saved field schema and current editor context are resolved again on the server.

## Conditional Logic

Top-level fields support simple Conditional Logic:

- equals
- not equals
- empty
- not empty

Rules inside one group use AND. Separate groups use OR. Conditional Logic controls editor visibility; an inactive field is skipped during save so its previously stored value is preserved.

## REST contract

REST exposure is opt-in per field. Registered meta receives a typed schema matching its storage contract, including object/array schemas for Group and Repeater fields. REST exposure is not required for normal native metadata access.

## Public developer API

Plugins can register runtime-owned Content Models through `CoreBlueprint\Core\ContentModels\Api`. Managed definitions are runtime-only, carry an owner identifier and are locked against normal admin mutation.

Registration is intended during the public registration hook, before Content Models runtime registration:

```php
add_action( 'cb_core_content_models_register', static function ( string $api ): void {
    $api::register_field_group( [
        'id'         => 'field_group_example',
        'title'      => 'Example',
        'post_types' => [ 'page' ],
        'fields'     => [
            [
                'id'    => 'field_example_label',
                'name'  => 'example_label',
                'label' => 'Example label',
                'type'  => 'text',
            ],
        ],
    ], 'my-plugin' );
} );
```

Consumers can inspect the merged runtime schema with `Api::schema()`, inspect typed field metadata with `Api::field_schema()`, and read native values with `Api::value()`.

## Content Models JSON Schema v1

**Content Models → Tools** provides the canonical public schema-only portability format. A document is identified by:

```json
{
  "format": "core-blueprint-content-models",
  "format_version": 1
}
```

Schema v1 carries only Content Models definitions: `post_types`, `taxonomies`, `option_pages` and `field_groups` (including their field definitions). Customer posts, terms, users, metadata values and option values are never part of this portability document.

Import rules are deliberately conservative:

- the complete document is validated before application;
- matching user-managed definitions are reported as conflicts;
- plugin-owned locked definitions block replacement;
- import is merge-based and never deletes definitions absent from the document;
- optional overwrite applies only to matching user-managed definitions;
- import/export governance records contain identifiers/counts, never customer values.

The JSON format is the portability contract. `SchemaTransfer` and the admin workflow are Base implementation details rather than a third-party PHP importer API.

## Native WordPress portability

The Native WordPress Importer is a Base-owned tool that projects WordPress runtime registrations into the canonical Content Models schema. It does not understand the plugin/theme/vendor that created a registration.

Discovery is limited to WordPress-native registration APIs:

- custom post types from the WordPress post-type registry;
- custom taxonomies from the taxonomy registry;
- explicitly registered post, term and user metadata.

The importer never scans arbitrary metadata keys to invent schema, never reconstructs Option Pages from menu hooks/options tables, and never copies customer values. Existing native metadata remains in its current WordPress storage under the same key.

Preview states are:

- **Ready** — the effective runtime registration can be represented by Content Models without known semantic loss;
- **Mapping required** — WordPress proves the storage/data contract, but Content Models UI semantics such as field label, Field Group and (for ambiguous scalar types) field presentation must be explicitly chosen;
- **Existing** — a matching Content Models definition already owns the target;
- **Unsupported** — equivalent behavior cannot be represented safely and is not guessed.

Registered metadata is not treated as a complete UI field definition. For example, a WordPress `boolean` type can prove boolean storage semantics and suggest **True / False**, but the field label and Field Group remain explicit user decisions. A registered `string` never automatically becomes Text, Email, URL, Color or Date.

The handover is staged and fail-closed:

`Discover → Review / map → Create signed plan → Disable original registrar → Apply`

Plans are user-scoped, short-lived, versioned and integrity-signed. They store normalized schema plus value fingerprints/counts, not customer values. Apply rejects the complete plan when the source registration still exists, Content Models state changed, a target conflict appeared, the plan expired/failed integrity validation, or known-key metadata values changed after review. A successful apply performs one merge of the complete reviewed schema.

For compatibility checks, Base may read values only for the exact registered metadata keys explicitly selected by the user. This is validation of a known schema key, not metadata discovery.

## Bricks adapter

Bricks integration is isolated under `ContentModels/Adapters/Bricks`. Core storage, field registration and admin code do not depend on Bricks.

When Bricks is active, post-context fields are exposed as typed Core Blueprint dynamic-data tags using the `cb_cm:` prefix. Rich values such as Gallery, Relations, Group and Repeater stay typed where the Bricks dynamic-data hook permits typed output; text interpolation converts them to safe textual representations.

Bricks is a consumer of Content Models, never a storage layer.

## Governance

Schema mutations and schema-transfer operations are audited. Logs record identifiers/counts and operational metadata, not customer field values.

The module-management capability is `cb_manage_content_models`.
