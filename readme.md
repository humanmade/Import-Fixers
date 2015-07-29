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
```


## internal-links
````
NAME
  wp fix internal_links

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
