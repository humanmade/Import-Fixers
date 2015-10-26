# Import-Fixers
Collection of WP-CLI commands to help fix up imports.

```
NAME
  wp fix

DESCRIPTION
  Commands to fix things within Post content, usually post-import.

SYNOPSIS
  wp fix <command>

SUBCOMMANDS
  internal-links      Tries to fix internal links.
  img-src-from-links  ?
```

## internal-links
````
NAME
  wp fix internal-links

DESCRIPTION
  Fixes internal links by finding URLs on `old_domain` and getting the current link to that post by looking in post_meta for a specific `meta_key` match.

SYNOPSIS
  wp fix internal_links --old_domain=<domain> [--meta_key=<_original_url>] [--enact]

Defaults to a dry-run mode.

OPTIONS
  --old_domain
    Previous domain name in links that need updating. Do NOT add protocol!

  [--meta_key]
    Post meta key name to check URLs against. Defaults to "_original_url".

  [--enact]
    Set this flag to actually make the replacements.
```

## img-src-from-links
````
NAME
  wp fix img-src-from-links

DESCRIPTION
  Repairs empty `<img src="">` attributes which are wrapped in a link to an image.

SYNOPSIS
  wp fix img_src_from_links

Defaults to a dry-run mode.
```
