# The stand's gateway for every non-mail channel (HIL-492, HIL-653).
#
# A Gateway that charges real money for real messages cannot be in a test suite, and
# a suite that skips the transport proves only that the code compiles. So the stand
# runs its own: the provider endpoints the framework clients speak to - Telegram
# Gateway and a generic HTTP SMS gateway - plus a handful of test-only routes that
# let a spec declare a number absent from Telegram and wipe the state.
#
# What it does NOT carry is a way to read what arrived. Every caught message is
# forwarded to Mailpit as a letter, so the one inbox a person already opens holds
# mail, SMS and Telegram alike, and a channel added later needs a stand endpoint
# rather than a new way to read it.
#
# php:8.4-cli with the built-in server and nothing installed: it serves a few JSON
# routes, speaks plain SMTP to the stand's relay, and keeps its state in one file,
# so a dependency here would be a dependency to keep current for no gain. The
# version tracks the framework's own, not the reference mock's - the sources here
# are written in the same PHP as the rest of the repository, and an older image
# answers a syntax it cannot parse with an empty 200, which reads downstream as a
# gateway that refused rather than as a broken mock.
FROM php:8.4-cli

WORKDIR /app

COPY public /app/public
COPY src /app/src

# Router mode: every request re-enters public/index.php, which is also why the store
# is a file rather than memory - the built-in server keeps nothing between requests.
CMD ["php", "-S", "0.0.0.0:18000", "-t", "/app/public", "/app/public/index.php"]
