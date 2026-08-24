# Public changelog theming

Changelogify renders the public listing with
`changelogify-release-list.html.twig` and each release with
`changelogify-release.html.twig`. Copy either template into a site theme to
override it. The public library is `changelogify/public`.

## Stable template variables

The listing template receives `releases` and `pager`. Each release is a scalar
array containing:

- `title`: release title.
- `slug`: stable public slug.
- `date`: localized display date.
- `date_iso`: ISO 8601 date for the `time` element.
- `version`: optional version label.
- `excerpt`: plain-text summary of up to two items.
- `url`: canonical public detail URL.

The detail template receives `title`, `date`, `date_iso`, `version`, and
`sections`. Each section contains a translated `label` and `items`; public item
data is limited to the text needed for display. Entity objects, editorial
state, event identifiers, and provenance are not template variables.

Keep the heading order, semantic `time` elements, and item lists when
overriding templates. The supplied styles include visible keyboard focus,
long-content wrapping, responsive spacing, and colors with sufficient contrast
on the default surface.

## URLs and caching

List and detail pages emit canonical links. Detail canonicals always use the
current stable slug; historical slugs and legacy numeric paths return permanent
redirects without disclosing inaccessible drafts.

Render caches vary by interface language, content language, and permissions;
the listing also varies by pager query. Release cache tags invalidate detail
pages after edits or publication changes, and the release list tag invalidates
listing pages after create, edit, unpublish, republish, or delete operations.
Themes should preserve the render array's cache metadata and must not render
release entities independently.
