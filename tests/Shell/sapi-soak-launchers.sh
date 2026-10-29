#!/usr/bin/env bash
# Check argument routing without starting PHP, HTTP, or Redis.
set -euo pipefail
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
for script in sapi-saturate-serve.sh sapi-saturate-client.sh; do
    if [[ ! -f $root/local-scripts/$script ]]; then
        echo "SKIP: local-scripts/$script is not installed"
        exit 0
    fi
done
workspace=$(mktemp -d)
trap 'rm -rf -- "$workspace"' EXIT
mkdir -p "$workspace/with spaces/bin" "$workspace/with spaces/local-scripts"
cp "$root/local-scripts/sapi-saturate-serve.sh" "$root/local-scripts/sapi-saturate-client.sh" "$workspace/with spaces/local-scripts/"
cat > "$workspace/with spaces/bin/redis-client-tester-sapi" <<'STUB'
#!/usr/bin/env bash
printf '%s\0' "$@"
exit "${STUB_EXIT:-0}"
STUB
chmod +x "$workspace/with spaces/bin/redis-client-tester-sapi"

contains() {
    local wanted=$1 item
    shift
    for item in "$@"; do
        [[ $item != "$wanted" ]] || return 0
    done
    echo "missing argument: $wanted" >&2
    return 1
}

(cd / && "$workspace/with spaces/local-scripts/sapi-saturate-serve.sh" \
    --directory '/tmp/soak with spaces' --workers=8 --php-arg=-drelay.maxmemory=32m --daemon) > "$workspace/args"
mapfile -d '' -t args < "$workspace/args"
[[ ${args[0]} == serve && ${args[-1]} == --daemon ]]
contains --workers=4 "${args[@]}"
contains --php-arg=-drelay.eviction_policy=lru "${args[@]}"
[[ ${args[-3]} == --workers=8 && ${args[-2]} == --php-arg=-drelay.maxmemory=32m ]]
contains '/tmp/soak with spaces' "${args[@]}"

(cd / && "$workspace/with spaces/local-scripts/sapi-saturate-client.sh" \
    --directory '/tmp/soak with spaces' --requests=3 --request-timeout=90 -- \
    --port=6390 --seed=42 --prefix 'space $literal' --steps=5000) > "$workspace/args"
mapfile -d '' -t args < "$workspace/args"
[[ ${args[0]} == client && ${args[-1]} == --steps=5000 ]]
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
[[ $stage == 1 && ${client[-1]} == --request-timeout=90 ]]
contains --requests=3 "${client[@]}"
for arg in --client=relay --saturate-mode=churn --saturate-steps=3 --steps=100 --seconds=0.25 --port=6390 --seed=42 'space $literal' \
    "--hook=$workspace/with spaces/hooks/clamp-read-timeout.php"; do
    contains "$arg" "${workload[@]}"
done
(cd / && "$workspace/with spaces/local-scripts/sapi-saturate-client.sh") > "$workspace/args"
mapfile -d '' -t args < "$workspace/args"
[[ ${args[0]} == client ]]
contains --steps=100 "${args[@]}"
contains --seconds=0.25 "${args[@]}"
status=0
STUB_EXIT=23 "$workspace/with spaces/local-scripts/sapi-saturate-client.sh" --help > /dev/null || status=$?
[[ $status == 23 ]]
echo 'SAPI saturation wrappers: OK'
