# Vendored etcd protos

Upstream: **etcd v3.5.17**, `api/etcdserverpb/rpc.proto` (sha256 `7cf0d098aae57522a574bf591145e55539a15212885d43709536553833cd6f40`),
`api/mvccpb/kv.proto` (`76b0d596ecc8dd1b535be683777910a5b4fe0705fb57baccacfcc7d98d11821e`) and
`api/authpb/auth.proto` (`443e661f9e423d3376d513e76446e957ae0132c6f63fff4510fde3c7a587be05`),
fetched from `raw.githubusercontent.com/etcd-io/etcd/v3.5.17/<path>` — those hashes are of the
untouched downloads, so the two local edits below are what a diff against upstream shows.
`version.proto` is not here: it does not exist in v3.5.x. Upstream added it in v3.6 as
`api/versionpb/version.proto` and it declares only option extensions, which nothing in the
v3.5 API imports. Add it the day this client talks to a v3.6-only message.

Local edits, so a regeneration can be audited:

1. `option php_namespace` and `option php_metadata_namespace` after `package`, so the generated
   classes load as `Erikwang2013\Etcd\Proto\*` (composer psr-4) instead of the global
   `Etcdserverpb\` / `Mvccpb\` / `Authpb\` / `GPBMetadata\` namespaces.
2. the `gogoproto/gogo.proto` and `google/api/annotations.proto` imports and their option
   statements removed. Both are Go and grpc-gateway codegen hints that the PHP generator ignores,
   and both transitively import `google/protobuf/descriptor.proto` — a proto2 file that protoc
   28.3's PHP generator refuses to emit ("Can't generate PHP code for closed enum"). Verified
   neutral: generating with those imports present produced 114 byte-identical files except the
   three `GPBMetadata/*` ones, which only lose the import references.

Regenerate with `./generate.sh` (`protoc --php_out`, no PHP deps, `grpc_php_plugin` not needed).
`generated/` is committed because protoc is a build-time tool most users of this library do not
have, and because the wire format the client speaks should be readable and diffable in the repo.
