#!/bin/sh
# Regenerate protos/generated from the .proto files here (see README.md).
# Needs protoc on PATH (libprotoc 28.3 was used). No grpc_php_plugin: PHP code only.
set -eu
cd "$(dirname "$0")"
rm -rf generated
mkdir generated
protoc -I . --php_out=generated \
    etcd/api/etcdserverpb/rpc.proto \
    etcd/api/mvccpb/kv.proto \
    etcd/api/authpb/auth.proto
echo "wrote $(find generated -type f | wc -l) files"
