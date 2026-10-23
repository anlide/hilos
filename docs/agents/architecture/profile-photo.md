# Profile Photos

Read this before enabling or changing a person's published photo (HIL-1205).

## Activation

`HilosFeature::PROFILE_PHOTO` requires AUTH, FILES, UPLOADS and IMAGES. Declare
the feature, run `db:framework:pull` and apply its generated project migrations
for `user_photo` and the dependency units, following the
[migration canon](../orm/migrations.md#framework-tables-in-a-project-hil-1326).
The unit's current form is `create_hilos_user_photo.sql` with its down stub.
The feature adds the framework
upload target `hilos_profile_photo` and image variant `hilos_avatar`; a project
must not declare either name in its own catalogs. The target accepts a JPEG up
to 512 KiB, checks the MIME type from its bytes, requires sign-in, and
publishes the file as `public`. The 256×256 WEBP COVER variant is the one used
for display.

The photo is checked by [framework moderation](moderation.md): with
`MODERATION`, the photo uses the `hilos_profile_photo` kind and the common
check door; without it, a valid upload publishes immediately.

The former project checker seam `Hilos::PROFILE_PHOTO_CHECKER` and the separate
`hilosProfilePhotoChecks` queue the feature mounted were removed in HIL-1625;
the feature mounts no runtime state of its own.

When HILOS_USERS is enabled too, bind the framework browser table
`HilosUserPhotoBrowserTable` to the user card. Its `photo` field is personal:
an administrator receives its variant URL, while the admin view mode hides it.

## Durable And Live State

`hilos_user_photo` has one row per person, keyed by `user_id`, with `file_id`
and `set_at`. Both keys are foreign keys with RESTRICT: the row must leave before
its person or its registry file. The person's agent writes the row, the first
one included — one photo per person (owner's decision, 2026-10-10, HIL-1409).
The users library judges the upload and the file; after the write it binds the
new file, removes the former one and refreshes sessions. Erasure removes the
row at the holder. Restore anonymization purges the table whole. A file absent
after a restore leaves the browser's initials as the visible fallback.

With `MODERATION`, the users library judges the submission first — the
feature, the acting person, no photo check already pending on that connection,
and a complete upload of the photo target owned by that person — then opens one
`hilos_profile_photo` check per connection in the shared
[`hilosModerationChecks`](moderation.md#one-door-and-one-moderator) queue: its own set of it,
cut by the verdict recipient, which for the photo kind is the users library.
The row names the connection, the person and the upload, and carries a fresh
`requestId` and the `deadlineAt` that `moderation.check_timeout_sec` gives it
at the opening. A second submission on that connection is refused while the
check exists. The moderator reads the completed upload and sends
`hilos_moderation_verdict` back to the users library; the old photo stays
visible until approval.

The library applies a verdict only while the row still holds the request id it
names, so a late or repeated verdict — of an earlier attempt on the same upload
too — changes nothing, and a verdict of any other kind is not applied to the
photo. It removes the row before it publishes or refuses. Once a second it
sweeps its photo checks: one past its deadline ends as `service_unavailable`,
which is how a stopped moderator ends a wait; one whose connection closed or
now carries somebody else is removed without a publication and without a frame.
While this process holds a frozen copy of the connection, nothing is decided on
it, and the deadline ends the wait. Closing the photo dialog does not stop the
check; closing the connection makes the library sweep its pending row and the
uploads agent clean up the upload. Removing the photo ends the photo check of
that connection and no check of another kind.

## Wire And Publication

The browser submits `hilos_profile_photo_set` with `clientUploadId`, or
`hilos_profile_photo_remove` with no client fields. The shared
`hilos_moderation_check` frame, `{kind: 'hilos_profile_photo', checking}`,
reports the pending flag to that connection: `true` when the check opens,
`false` when it ends. The SDK's photo flow reads that kind alone
(`PROFILE_PHOTO_KIND`, `SIGNAL_MODERATION_CHECK` and `moderationCheckSchema`
from `@hilos/core`) and ignores a malformed frame.

Approval asks the files registry
to publish; its `hilos_profile_photo_published` answer carries the registry
file id. The write and the removal go to the person's agent as
`hilos_user_photo_put` and `hilos_user_photo_remove`. After the write the new
file is bound and the former file is removed. Removal deletes the row first,
then removes its file. Both endings send
`hilos_user_sessions_restate` so every open session receives a fresh handshake
identity with `currentUser.photo`; the admin card follows DB sync.

The profile root's first `page_response` includes `profilePhoto`, a boolean
answering whether this project enabled the feature. The photo URL is built by
`HilosFiles::downloadPath($fileId, 'hilos_avatar')`. It is public, including for
a guest without a cookie. Failed image loading falls back to initials in the
SDK avatar component.

Showing a person's photo to others: the address is the `photo` property of the
photo row's view item (`Hilos\Database\View\Item\UserPhoto::photo`), the one
place that builds it. A project that shows somebody's photo joins `userPhotos`
by `user_id` to its own list row or to its page's data and reads `photo` as a
field there — chat does so for the roster of its main page and for
`/user/{id}` (HIL-1300). It stays live by DB sync: a new photo or a removal
reaches an open screen as a change of that row, with no frame of its own. The
admin user card reads the same property.

A rejected verdict sends an action error to the submitting connection.
Content refusals additionally emit optional `user.photo_rejected`; a moderator
that is unavailable sends no notification. The rejection copy and reason map
are in `ProfilePhotoRefusal`.

## Erasure, Copy And Cluster Limit

Account erasure removes the photo row inside its transaction and hands the
file id to the files library after commit. A personal data copy writes a
`profile_photo` section with its set moment and the original cropped JPEG.
The image variant is derived and is not copied separately.

The moderator reads the upload's temporary file from the cluster's tmp directory,
even when it lives on another node. The files registry's shared storage and the
image renderer follow their own cluster rules in
[files-registry.md](files-registry.md) and [images.md](images.md).
