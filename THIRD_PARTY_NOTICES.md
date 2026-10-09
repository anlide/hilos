# Third-party notices

## Unicode CLDR country currencies

The 51 country-to-ISO-4217 currency-code mappings in
`framework/backend/I18n/Catalog/BuiltInI18nCatalog.php` are derived from the
current tender currency for each region in
[Unicode CLDR 48.2 supplementalData.xml](https://github.com/unicode-org/cldr/blob/release-48-2/common/supplemental/supplementalData.xml).
Copyright © 1991–2026 Unicode, Inc. Used under the
[Unicode License v3](https://www.unicode.org/license.txt).

The languages, country currency symbols, locales, country default locales and
country names in that catalog are ported from the hleb catalog.

## Common passwords

`framework/data/common-passwords.txt` is derived from the UK NCSC's 2019
“100k most used passwords” list, based on Have I Been Pwned Pwned Passwords
by Troy Hunt. HIBP password data is attributed under
[Creative Commons Attribution 4.0](https://creativecommons.org/licenses/by/4.0/).

The source distributed by [SecLists](https://github.com/danielmiessler/SecLists)
is pinned to commit `1a7bb9127eca9e6ff2fc0301c597fe6e16a0cb56`, file
[Passwords/Common-Credentials/100k-most-used-passwords-NCSC.txt](https://github.com/danielmiessler/SecLists/blob/1a7bb9127eca9e6ff2fc0301c597fe6e16a0cb56/Passwords/Common-Credentials/100k-most-used-passwords-NCSC.txt).

Hilos removes trailing CR/LF, keeps passwords at least
`PasswordPolicy::MIN_LENGTH` bytes long, lowercases with PHP `mb_strtolower()`,
removes duplicates, sorts with `SORT_STRING`, and writes one password per line
with LF endings and a final LF. The framework maintains this derived list;
projects do not configure it. The update procedure is documented on
`Hilos\Auth\PasswordPolicy`.

SecLists is distributed under the following
[MIT license](https://github.com/danielmiessler/SecLists/blob/1a7bb9127eca9e6ff2fc0301c597fe6e16a0cb56/LICENSE):

MIT License

Copyright (c) 2018 Daniel Miessler

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
