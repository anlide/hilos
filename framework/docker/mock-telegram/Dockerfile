# The stand's Telegram Gateway (HIL-492).
#
# A Gateway that charges real money for real messages cannot be in a test suite, and
# a suite that skips the transport proves only that the code compiles. So the stand
# runs its own: the same two provider endpoints the framework client speaks to, plus
# a handful of test-only routes that let a spec read what "arrived" and declare a
# number absent from Telegram. Mailpit does this job for mail; nothing packaged does
# it for the Gateway, so it is ours.
#
# php:8.4-cli with the built-in server and nothing installed: it serves a few JSON
# routes and keeps its state in one file, so a dependency here would be a dependency
# to keep current for no gain. The version tracks the framework's own, not the
# reference mock's — the sources here are written in the same PHP as the rest of the
# repository, and an older image answers a syntax it cannot parse with an empty 200,
# which reads downstream as a gateway that refused rather than as a broken mock.
FROM php:8.4-cli

WORKDIR /app

COPY public /app/public
COPY src /app/src

# Router mode: every request re-enters public/index.php, which is also why the store
# is a file rather than memory - the built-in server keeps nothing between requests.
CMD ["php", "-S", "0.0.0.0:18000", "-t", "/app/public", "/app/public/index.php"]
