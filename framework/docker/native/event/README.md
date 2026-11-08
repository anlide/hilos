# Hilos ext-event build

This directory builds [PECL event 3.1.6](https://pecl.php.net/package/event/3.1.6)
with the local `peer-certificate.patch` for PHP 8. The archive is fetched from
`https://pecl.php.net/get/event-3.1.6.tgz`. Its SHA256 is
`5b74554c6370aae8c284c8110fe27e071d3f663953c3eb762ffc429b0a3c83a2`.
`checksums.sha256` covers both the archive and the patch; installation stops on
any mismatch or rejected patch hunk. The upstream source is not vendored.

## API and ownership

The patch adds `EventBufferEvent::sslGetPeerCertificate(): ?string` to the
PHP 8 implementation, stub, arginfo, declaration and method table. The existing
`php8/arginfo.h` includes `php_event_arginfo.h` and needs no separate declaration.
After configure rewrites the stub, the installer marks the patched arginfo as
current. Regenerating all upstream arginfo with PHP 8.4 fails on unrelated
upstream stub signatures (for example `Event::set()`'s non-nullable `what`
parameter with a null default), so the build uses the reviewed header directly.
It returns the peer's leaf certificate encoded as PEM, or `null` when no leaf
or SSL object is available (including before the handshake and after `free()`).
Allocation or PEM encoding failure raises an `Error`.

The getter does **not** establish trust. Configure CA verification and, on a
mutual TLS server, require the client certificate. Check the expected peer
identity after a successful handshake. A certificate fetched during a failed
handshake must never stand in for that successful verification.

`bufferevent_openssl_get_ssl()` borrows the SSL handle; the getter never frees
it. `SSL_get_peer_certificate()` takes an X509 reference, released on every
path. The temporary memory BIO is freed after copying its bytes into the PHP
string, or on encoding failure. Repeated reads do not consume the certificate.

The PHP 8 patch also repairs native buffer-event lifetime. `dtor_obj` retires
the owned bufferevent before dropping its PHP base and SSL callback context;
`free_obj` and explicit `free()` share the same idempotent release. The native
pointer is cleared before release, and native callbacks are cleared before
their PHP owners can disappear. A live borrowed HTTP wrapper keeps upstream's
counted self reference as a native root outside the PHP cycle graph: the HTTP
connection can still hold its address as a callback argument after ordinary
PHP references disappear. It remains retained until explicit `free()` or PHP
shutdown, even if the HTTP connection has already ended. Call `free()` while
the borrowed native handle is still valid. It detaches callbacks only when
their argument belongs to that wrapper, then clears its borrowed pointer and
allows GC to collect the counted self. It never disables, frees or replaces
the HTTP handle, nor clears callbacks installed by another alias. Destruction
at PHP shutdown does not dereference that borrowed handle. No PHP transport
destructor closes a still-live native alias's caller-owned socket.

SSL factories retain their PHP context, and SSL filters retain their underlying
PHP owner. These references and callback cycles are visible to Zend GC; a
paired event's self reference is borrowed, while a live HTTP wrapper's counted
self represents the native root described above. With `OPT_CLOSE_ON_FREE`, libevent
owns SSL release; otherwise the extension frees SSL after retiring the
bufferevent. A closing filter reserves a separate native underlying reference
so it cannot consume the PHP wrapper's reference. Retained input/output aliases
do not become independent owners of the parent's native buffers.

## Installation

Use a PHP 8 image with sockets installed and loaded before event, PHP build
tools (`phpize`, `php-config`, a C compiler, make and pkg-config), libevent and
OpenSSL development headers, curl, CA certificates, tar, patch and sha256sum.
The official PHP CLI image supplies the PHP tools and `$PHP_INI_DIR`.
`install.sh` explicitly enables event OpenSSL and sockets support and writes
`30-event.ini`; the caller must load sockets first (for example `20-sockets.ini`).
It preserves a copy of this notice in `/usr/local/share/doc/hilos-event/README.md`.

BuildKit supplies these files as the named context `hilos-event`. With Compose
2.17 or later, the build declares `additional_contexts.hilos-event` using a
path resolved from that Compose model. For the framework test carrier:

```sh
docker compose -f framework/docker/docker-compose.yml build hilos-cli-test
```

The equivalent direct build from the repository root is:

```sh
docker build --build-context hilos-event=framework/docker/native/event \
  -f framework/docker/Dockerfile.test-cli framework/docker
```

A consumer copies the context with `COPY --from=hilos-event . /tmp/hilos-event/`
and runs `sh /tmp/hilos-event/install.sh`. Keep the principal build context
local to that consumer; do not duplicate the patch or fetch an unpublished one.

## Updating and checking

1. Obtain the new official archive and review its source, license, PHP 8
   declarations and SSL ownership against this patch.
2. Update the fixed archive URL/version in the installer and this notice;
   compute its SHA256 independently. Rebase the patch without fuzz and keep
   the stub, nullable arginfo, declaration and method table in agreement.
3. Recompute `checksums.sha256` for the archive and the reviewed local patch.
   The installer checks these files before extracting or building anything.
4. Build the test carrier and run from the repository root:

   ```sh
   composer run test:framework:unit -- --filter 'NativePeerCertificateTest|TlsSocketTransportMutualTest'
   composer run test:framework:unit
   ```

   The installer checks the internal, public, non-static, zero-argument
   `?string` method signature. The native tests exercise real EventBufferEvent
   TLS in both directions, compare leaf certificates and names, and reject
   missing client certificates and foreign CAs. Version or class existence
   alone is not evidence of this capability.
   `NativeHttpBorrowedBufferEventTest` keeps a real HTTP request open, installs
   borrowed callbacks, releases ordinary PHP references and collects cycles
   before later native input. Its lifetime assertion stops before a dangling
   callback could be polled on an unrepaired module; native memory diagnostics
   exercise that late callback separately.

Rebuild every consumer after changing these assets. The getter capability check
cannot identify the lifetime repair: verify the patch checksum, actual build
inputs and loaded module provenance as well as native lifetime tests. Native
shutdown diagnostics must keep the original retained-owner cases unchanged and
require zero invalid accesses and zero live or lost allocations for them.

The supplementary SSL-filter control has a separate known limitation in
libevent 2.1.13 with OpenSSL 3.5.7: its cached `BIO_s_bufferevent` method table
is reported lost at module unload (112 direct and 12 indirect bytes on the
verified runtime). The original and repaired modules report the same allocation
without invalid accesses. This remains a nonzero Valgrind result, compared
against the matching original-module baseline; it is not a general leak
allowance. Recheck that comparison when the runtime changes. No libevent fork,
cleanup workaround or suppression is included.

## Attribution and license

Upstream event is maintained by Ruslan Osmanov, with contributions from Anatol
Belski and Remi Collet. Its PHP 8 source headers credit Ruslan Osmanov and
Copyright (c) 1997-2023 The PHP Group. The Hilos patch modifies that extension;
it is not an upstream release. The patch is distributed under the same PHP
License 3.01, reproduced from the pinned archive below. Retain this notice
with source and binary distributions.

This product includes PHP software, freely available from
<http://www.php.net/software/>.

```text
--------------------------------------------------------------------
                  The PHP License, version 3.01
Copyright (c) 1999 - 2016 The PHP Group. All rights reserved.
--------------------------------------------------------------------

Redistribution and use in source and binary forms, with or without
modification, is permitted provided that the following conditions
are met:

  1. Redistributions of source code must retain the above copyright
     notice, this list of conditions and the following disclaimer.

  2. Redistributions in binary form must reproduce the above copyright
     notice, this list of conditions and the following disclaimer in
     the documentation and/or other materials provided with the
     distribution.

  3. The name "PHP" must not be used to endorse or promote products
     derived from this software without prior written permission. For
     written permission, please contact group@php.net.

  4. Products derived from this software may not be called "PHP", nor
     may "PHP" appear in their name, without prior written permission
     from group@php.net.  You may indicate that your software works in
     conjunction with PHP by saying "Foo for PHP" instead of calling
     it "PHP Foo" or "phpfoo"

  5. The PHP Group may publish revised and/or new versions of the
     license from time to time. Each version will be given a
     distinguishing version number.
     Once covered code has been published under a particular version
     of the license, you may always continue to use it under the terms
     of that version. You may also choose to use such covered code
     under the terms of any subsequent version of the license
     published by the PHP Group. No one other than the PHP Group has
     the right to modify the terms applicable to covered code created
     under this License.

  6. Redistributions of any form whatsoever must retain the following
     acknowledgment:
     "This product includes PHP software, freely available from
     <http://www.php.net/software/>".

THIS SOFTWARE IS PROVIDED BY THE PHP DEVELOPMENT TEAM ``AS IS'' AND
ANY EXPRESSED OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO,
THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A
PARTICULAR PURPOSE ARE DISCLAIMED.  IN NO EVENT SHALL THE PHP
DEVELOPMENT TEAM OR ITS CONTRIBUTORS BE LIABLE FOR ANY DIRECT,
INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
(INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION)
HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT,
STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED
OF THE POSSIBILITY OF SUCH DAMAGE.

--------------------------------------------------------------------

This software consists of voluntary contributions made by many
individuals on behalf of the PHP Group.

The PHP Group can be contacted via Email at group@php.net.

For more information on the PHP Group and the PHP project,
please see <http://www.php.net>.

PHP includes the Zend Engine, freely available at
<http://www.zend.com>.

```
