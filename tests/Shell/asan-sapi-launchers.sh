#!/usr/bin/env bash
# Assert launcher argument routing with a fake harness; never contact Redis or load ASAN.
set -euo pipefail
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
for script in asan-sapi.sh asan-saturate-sapi.sh; do
    if [[ ! -f $root/local-scripts/$script ]]; then
        echo "SKIP: local-scripts/$script is not installed"
        exit 0
    fi
done
workspace=$(mktemp -d)
trap 'rm -rf -- "$workspace"' EXIT
unset FAILOVER
mkdir "$workspace/bin" "$workspace/scripts" "$workspace/local-scripts"
cp "$root/scripts/sapi-campaign.sh" "$workspace/scripts/"
cp "$root/local-scripts/asan-sapi.sh" "$root/local-scripts/asan-saturate-sapi.sh" "$workspace/local-scripts/"
cat > "$workspace/bin/harness" <<'STUB'
#!/usr/bin/env bash
[[ $PWD == "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)" ]] || exit 1
printf '%s\0' "$@"
STUB
chmod +x "$workspace/bin/harness"

contains() {
    local wanted=$1 item
    shift
    for item in "$@"; do
        [[ $item != "$wanted" ]] || return 0
    done
    return 1
}

expect_option() {
    local name=$1 expected=$2 actual=__missing__ index
    shift 2
    local args=("$@")
    for index in "${!args[@]}"; do
        if [[ ${args[index]} == "--$name" ]]; then
            actual=${args[index+1]:-__missing__}
        elif [[ ${args[index]} == "--$name="* ]]; then
            actual=${args[index]#*=}
        fi
    done
    if [[ $actual != "$expected" ]]; then
        printf '%s: expected %q, got %q\n' "$name" "$expected" "$actual" >&2
        exit 1
    fi
}

capture() {
    local script=$1 token stage=0
    shift
    (cd / && "$workspace/local-scripts/$script" "$@") > "$workspace/argv"
    local args=()
    mapfile -d '' -t args < "$workspace/argv"
    harness=() sapi=() workload=()
    for token in "${args[@]}"; do
        if [[ $token == -- ]]; then
            ((stage+=1))
        else
            case $stage in
                0) harness+=("$token") ;;
                1) sapi+=("$token") ;;
                2) workload+=("$token") ;;
                *) echo 'unexpected option separator' >&2; exit 1 ;;
            esac
        fi
    done
    [[ $stage == 2 && ${sapi[0]} == bin/sapi ]]
    contains --asan "${harness[@]}"
    expect_option php-ini conf.d/php.ini "${harness[@]}"
    contains -n "${harness[@]}"
    expect_option invocation-mode coercive "${workload[@]}"
    expect_option steps '{steps}' "${workload[@]}"
    ! contains --forks "${workload[@]}" || { echo 'unexpected CLI forks' >&2; exit 1; }
    expect_option run-log "${script%.sh}.log" "${harness[@]}"
    expect_option output "reproducers-${script%.sh}" "${harness[@]}"
}

for script in asan-sapi.sh asan-saturate-sapi.sh; do
    capture "$script"
    expect_option port 6379 "${harness[@]}"
    contains 7000 "${harness[@]}"
    ! contains --tls "${workload[@]}" || { echo 'unexpected TLS in plaintext run' >&2; exit 1; }
    expect_option workers 4 "${sapi[@]}"
    expect_option concurrency 2 "${sapi[@]}"
    expect_option requests 20 "${sapi[@]}"
    expect_option step-jitter 50 "${sapi[@]}"
    if [[ $script == asan-sapi.sh ]]; then
        expect_option jobs 4 "${harness[@]}"
        expect_option run-timeout 120 "${harness[@]}"
        contains --raw "${workload[@]}"
    else
        expect_option jobs 2 "${harness[@]}"
        expect_option run-timeout 600 "${harness[@]}"
        contains '-drelay.maxmemory={2m-16m}' "${harness[@]}"
        expect_option saturate-mode churn "${workload[@]}"
    fi

    capture "$script" --tls --jobs 7 --workers=8 --concurrency 4 --requests=9 \
        --step-jitter 25 --idle-ms=10 --request-timeout 45 --run-timeout=900 \
        --runs 1 --steps=2000 --keys 12 --seconds=0 --seed=42 --prefix 'space $literal'
    expect_option port 16379 "${harness[@]}"
    contains 18000 "${harness[@]}"
    contains --tls "${workload[@]}"
    expect_option tls-options '{"verify_peer":false,"verify_peer_name":false}' "${workload[@]}"
    expect_option jobs 7 "${harness[@]}"
    expect_option steps 2000 "${harness[@]}"
    expect_option runs 1 "${harness[@]}"
    expect_option run-timeout 900 "${harness[@]}"
    expect_option workers 8 "${sapi[@]}"
    expect_option concurrency 4 "${sapi[@]}"
    expect_option requests 9 "${sapi[@]}"
    expect_option step-jitter 25 "${sapi[@]}"
    expect_option idle-ms 10 "${sapi[@]}"
    expect_option request-timeout 45 "${sapi[@]}"
    expect_option keys 12 "${workload[@]}"
    expect_option seconds 0 "${workload[@]}"
    expect_option seed 42 "${workload[@]}"
    expect_option prefix 'space $literal' "${workload[@]}"

    "$workspace/local-scripts/$script" --help > "$workspace/help"
    for option in --jobs --workers; do
        status=0
        "$workspace/local-scripts/$script" "$option" > "$workspace/argv" 2> "$workspace/error" || status=$?
        [[ $status == 78 && ! -s $workspace/argv ]]
    done
    printf '%s: OK\n' "$script"
done
