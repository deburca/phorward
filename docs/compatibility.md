# What stays stable

phorward is **experimental in 0.1**: it works and is used, but its surface may still change. This page names the parts
you can rely on to change deliberately, with a version bump, and not by accident.

| Surface | Promise |
|---|---|
| **Protocol** ([protocol.md](protocol.md)) | Version `1`. Within a version, fields and options are only **added**; clients ignore fields they do not know. A breaking change is a new protocol number, and a server answers an unknown number with `protocol_mismatch`, so a mismatch is an error message and not a surprise |
| **Exit statuses** | `0` passed, `1` a check failed, `2` configuration or request error, `70` infrastructure, `75` busy. Not renumbered |
| **Configuration keys** ([configuration.md](configuration.md)) | Names and meanings of the documented keys. A key may be added; one is renamed or removed only in a new minor version while 0.x, with a note in the changelog |
| **Image labels** | `phorward.profile`, `phorward.module-name`, `phorward.module-path` are read by the server tools |
| **Profile manifests** ([profiles.md](profiles.md)) | The documented keys. A profile you write yourself keeps working across patch releases |
| **Policy file** ([policy.md](policy.md)) | Documented keys and their defaults |
| **JSON reports** (`run` and `verify` with `--format json`) | Fields are added, not removed or retyped, within a protocol version |

Not promised: the PHP classes under `src/` (they are the implementation of the command line, not a library), the
wording of human-readable reports, and the exact set of tool versions in an image (images are rebuilt from lock files;
`/opt/TOOL-VERSIONS` and the report footer say what a run used).

Versions follow semantic versioning once 1.0 is reached; before that, a minor version may carry a breaking change to
the surfaces above, and says so.
