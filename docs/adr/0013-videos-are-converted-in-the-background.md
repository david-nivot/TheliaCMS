# 13. Videos are converted in the background, under the same id

Date: 2026-11-10. Status: accepted.

## Context

A phone records HEVC in a `.mov`, or H.264 in an `.mp4`, of 20 to 70 MB for a
minute. The library took MP4 only, 50 MB at most, so every such video was
re-encoded by hand and given a poster by hand before a page could use it.
Pages keep a video as its id and publish `/video-library/<id>.mp4`: whatever
converts the file must not change that address.

## Decision

- **The raw file is stored as sent, then replaced.** TheliaLibrary stores
  QuickTime and WebM files as videos (`LibraryImageFileNamer::VIDEO_MIME_TYPES`),
  without converting anything. A background job converts the file and puts it
  back with `LibraryImageService::updateImage()`, which keeps the row, so the
  id and the address, and removes the raw file.
- **The job is a Messenger message on the core's `async_heavy` queue**, the one
  of exports and imports: a conversion lasts minutes and must not hold up the
  mails. The module sends the transport name with the message (a
  `TransportNamesStamp`) rather than routing the class, since a module has no
  hand on the routing of the framework. Where the shop has no queue
  (`MESSENGER_TRANSPORT_DSN` empty) the core runs the job at once, in the
  request that sent the video.
- **The state lives in `cms_video_conversion`**, one row per converted video:
  `processing`, `ready`, `failed` with a code and the last line of what ffmpeg
  said, and the poster. A video without a row is a web file and reads as ready.
  The ids of the library are not foreign keys, as elsewhere in the schema.
- **A job that fails records and returns.** The same file fails the same way,
  so a retry would only repeat it; the failed video stays in the library with
  its reason, can be deleted or replaced, and cannot be picked into a page.
- **ffmpeg is a program of the server**, `ffmpeg` in the PATH or
  `THELIA_CMS_FFMPEG_PATH`. Without it the library keeps its earlier rules:
  MP4 only, 50 MB at most, nothing converted, and a MOV or a WebM is refused
  with a message that names ffmpeg.
- **The poster is a frame taken about two seconds into the converted file**
  (the first frame of a shorter clip), stored as a decorative image of the CMS
  and linked on the row. A component with a `poster` image prop the author left
  empty is given it, per row in a list.

## Consequences

- The module needs the TheliaLibrary that stores QuickTime and WebM; with an
  older one the screens behave as before (`VideoIntake::converts()` is false).
- A job killed in the middle (worker stopped, memory) leaves its row
  `processing`; replacing the file of the video starts it again.
- The PHP limits (`upload_max_filesize`, `post_max_size`) must exceed the raw
  ceiling of 100 MB: the screen says so when they do not.
