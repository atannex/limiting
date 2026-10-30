# Atannex Limiting

Rate limiting for Laravel, built to protect a site. It controls how often someone can do something (log in, post a form, call an API) and blocks them for a while when they go over the limit. Repeat offenders get longer and longer bans, trusted addresses can be let through and bad ones blocked. Limits are set as named policies and attached to controllers with an attribute.

## Install

```bash
composer require atannex/limiting
```
