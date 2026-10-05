# Profile Photos

Read this before enabling or changing a person's published photo (HIL-1205).

## Activation

`HilosFeature::PROFILE_PHOTO` requires AUTH, FILES, UPLOADS and IMAGES. Copy
`create_hilos_user_photo.sql` and its down stub into the project's migration
track. The feature mounts `hilosProfilePhotoChecks` and adds the framework
upload target `hilos_profile_photo` and image variant `hilos_avatar`; a project
must not declare either name in its own catalogs. The target accepts a JPEG up
to 512 KiB, checks the MIME type from its bytes, requires sign-in, and
publishes the file as `public`. The 256×256 WEBP COVER variant is the one used
for display. A project may set `Hilos::PROFILE_PHOTO_CHECKER` to a registered
agent type; null publishes immediately.

When HILOS_USERS is enabled too, bind the framework browser table
`HilosUserPhotoBrowserTable` to the user card. Its `photo` field is personal:
an administrator receives its variant URL, while the admin view mode hides it.

## Durable And Live State

`hilos_user_photo` has one row per person, keyed by `user_id`, with `file_id`
and `set_at`. Both keys are foreign keys with RESTRICT: the row must leave before
its person or its registry file. The users library owns its writes. Restore
anonymization purges the table whole. A file absent after a restore leaves the
browser's initials as the visible fallback.

When a checker is configured, the users library keeps one pending check per
connection in `hilosProfilePhotoChecks`, with accept key, user id, upload id and
start time. It exists only until the verdict or the connection closes. The
checker reads the completed upload and sends `hilos_profile_photo_verdict` back
to the users library. A second submission on that connection is refused while
the check exists. Closing the photo dialog does not stop the check; closing the
connection makes the library sweep its pending row and the uploads agent clean
up the upload.

## Wire And Publication

The browser submits `hilos_profile_photo_set` with `clientUploadId`, or
`hilos_profile_photo_remove` with no client fields. `hilos_profile_photo_check`
reports the pending flag to that connection. Approval asks the files registry
to publish; its `hilos_profile_photo_published` answer carries the registry
file id. One transaction inserts or replaces the person's photo row. After
commit the new file is bound and the former file is removed. Removal deletes
the row first, then removes its file. Both endings send
`hilos_user_sessions_restate` so every open session receives a fresh handshake
identity with `currentUser.photo`; the admin card follows DB sync.

The profile root's first `page_response` includes `profilePhoto`, a boolean
answering whether this project enabled the feature. The photo URL is built by
`HilosFiles::downloadPath($fileId, 'hilos_avatar')`. It is public, including for
a guest without a cookie. Failed image loading falls back to initials in the
SDK avatar component.

A rejected verdict sends an action error to the submitting connection.
Content refusals additionally emit optional `user.photo_rejected`; a checker
that is unavailable sends no notification. The rejection copy and reason map
are in `ProfilePhotoRefusal`.

## Erasure, Copy And Cluster Limit

Account erasure removes the photo row inside its transaction and hands the
file id to the files library after commit. A personal data copy writes a
`profile_photo` section with its set moment and the original cropped JPEG.
The image variant is derived and is not copied separately.

The checker reads the upload's temporary file from the cluster's tmp directory,
even when it lives on another node. The files registry's shared
storage and the image renderer follow their own cluster rules in
[files-registry.md](files-registry.md) and [images.md](images.md).
