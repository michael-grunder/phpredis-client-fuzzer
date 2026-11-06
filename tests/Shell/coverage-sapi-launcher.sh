#!/usr/bin/env bash
# Verify the local coverage preset without starting PHP, HTTP or Redis.
set -euo pipefail
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
script=${COVERAGE_CLIENT_SCRIPT:-$root/local-scripts/coverage-sapi-client}
if [[ ! -f $script ]]; then
    echo 'SKIP: local coverage client is not installed'
    exit 0
fi
workspace=$(mktemp -d)
trap 'rm -rf -- "$workspace"' EXIT
mkdir -p "$workspace/with spaces/bin" "$workspace/with spaces/local-scripts"
cp "$script" "$workspace/with spaces/local-scripts/coverage-sapi-client"
chmod +x "$workspace/with spaces/local-scripts/coverage-sapi-client"
cat > "$workspace/with spaces/bin/sapi" <<'STUB'
#!/usr/bin/env bash
printf '%s\0' "$@"
exit "${STUB_EXIT:-0}"
STUB
chmod +x "$workspace/with spaces/bin/sapi"
launcher="$workspace/with spaces/local-scripts/coverage-sapi-client"
(cd / && "$launcher" --jobs=1-8 --requests=3 -- --port=6390 --seed=42 --prefix 'space $literal') > "$workspace/args"
mapfile -d '' -t args < "$workspace/args"
[[ ${args[0]} == client && ${args[-1]} == 'space $literal' ]]
client=() workload=() stage=0
for arg in "${args[@]}"; do
    if [[ $arg == -- ]]; then
        ((stage+=1))
    elif (( stage == 0 )); then
        client+=("$arg")
    else
        workload+=("$arg")
    fi
done
[[ $stage == 1 && ${client[-2]} == --jobs=1-8 && ${client[-1]} == --requests=3 ]]
contains() {
    local wanted=$1 item
    shift
    for item in "$@"; do
        [[ $item != "$wanted" ]] || return 0
    done
    echo "missing argument: $wanted" >&2
    return 1
}
for arg in --steps=300 --seconds=2 --compression=none,lzf,zstd,lz4 --relay-distribute=any \
    --include=local,flush,stateful --saturate-mode=churn --port=6390 --seed=42 \
    '--weight=@local:1' '--weight=@scan:3' '--weight=fullscan:0.05' '--weight=@flush:0.002' \
    '--commands=@cached,@invalidating,@scan,eval,eval_ro,evalsha,evalsha_ro,flushmemory,setoption,getoption,addallowpatterns,addignorepatterns,_pack,_unpack,_serialize,_unserialize,_compress,_uncompress,-flushall,-flushdb' \
    '--scenarios=cache-hll,cache-collections,transaction-exec,transaction-discard,transaction-cross-slot-replies,transaction-error-alignment,pipeline-replies,lua-replies' \
    "--hook=$workspace/with spaces/hooks/adaptive.php" \
    --hook-arg=adaptive.width=512 --hook-arg=adaptive.depth=4 \
    --hook-arg=adaptive.minEvents=4 --hook-arg=adaptive.minRatio=2 \
    "--hook=$workspace/with spaces/hooks/no-msgpack.php"; do
    contains "$arg" "${workload[@]}"
done
for mode in info list; do
    (cd / && "$launcher" "$mode" --help) > "$workspace/args"
    mapfile -d '' -t args < "$workspace/args"
    [[ ${#args[@]} == 2 && ${args[0]} == "$mode" && ${args[1]} == --help ]]
done
status=0
STUB_EXIT=23 "$launcher" --help > /dev/null || status=$?
[[ $status == 23 ]]
server=${COVERAGE_SERVER_SCRIPT:-$root/local-scripts/coverage-sapi-server}
if [[ -f $server && -f $root/local-scripts/sapi-saturate-serve.sh ]]; then
    cp "$server" "$root/local-scripts/sapi-saturate-serve.sh" "$workspace/with spaces/local-scripts/"
    chmod +x "$workspace/with spaces/local-scripts/coverage-sapi-server"
    : > "$workspace/fake relay.so"
    for override in '' --php-arg=-dmemory_limit=8G; do
        extra=()
        [[ -z $override ]] || extra+=("$override")
        (cd / && PHP_BIN="$workspace/with spaces/bin/sapi" RELAY_SO="$workspace/fake relay.so" \
            "$workspace/with spaces/local-scripts/coverage-sapi-server" "${extra[@]}") > "$workspace/args"
        mapfile -d '' -t args < "$workspace/args"
        contains --php-arg=-dmemory_limit=4G "${args[@]}"
        contains "--php-arg=-dextension=$workspace/fake relay.so" "${args[@]}"
        memory=''
        for arg in "${args[@]}"; do
            [[ $arg != --php-arg=-dmemory_limit=* ]] || memory=$arg
        done
        [[ $memory == "${override:---php-arg=-dmemory_limit=4G}" ]]
    done
fi
echo 'SAPI coverage wrapper: OK'
