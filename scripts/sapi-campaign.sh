#!/usr/bin/env bash
# Sourced by the local SAPI launchers; the marker survives setsid and reparenting.
campaign_directory=$(mktemp -d "${TMPDIR:-/tmp}/redis-sapi-campaign.XXXXXXXXXX")
export REDIS_CLIENT_TESTER_SAPI_CAMPAIGN=$campaign_directory
campaign_pid=
campaign_helpers=()

campaign_signal() {
    local signal=$1 path pid
    local marker="REDIS_CLIENT_TESTER_SAPI_CAMPAIGN=$campaign_directory"
    while IFS= read -r path; do
        pid=${path#/proc/}
        pid=${pid%/environ}
        [[ $pid != "$$" && $pid != "$BASHPID" ]] || continue
        # Recheck ownership immediately before signalling; never match a PHP name or port.
        if grep -zFxq -- "$marker" "$path" 2>/dev/null; then
            kill -"$signal" -- "$pid" 2>/dev/null || true
        fi
    done < <(grep -zlFx -- "$marker" /proc/[0-9]*/environ 2>/dev/null || true)
}

campaign_cleanup() {
    local status=$?
    trap '' INT TERM HUP
    trap - EXIT
    if (( ${#campaign_helpers[@]} )); then
        kill -TERM -- "${campaign_helpers[@]}" 2>/dev/null || true
    fi
    campaign_signal TERM
    sleep 0.5
    campaign_signal KILL
    if [[ -n $campaign_pid ]]; then
        wait "$campaign_pid" 2>/dev/null || true
    fi
    rmdir -- "$campaign_directory"
    exit "$status"
}

campaign_interrupt() {
    # A background child can inherit SIGINT ignored; SIGTERM reaches the same harness handler.
    if [[ -n $campaign_pid ]]; then
        kill -TERM "$campaign_pid" 2>/dev/null || true
    fi
}

campaign_wait() {
    campaign_pid=$!
    local status=0
    while true; do
        wait "$campaign_pid" && status=0 || status=$?
        # wait is interrupted when a trap runs; keep waiting for the actual harness status.
        kill -0 "$campaign_pid" 2>/dev/null || return "$status"
    done
}

trap campaign_cleanup EXIT
trap campaign_interrupt INT
trap '[[ -z $campaign_pid ]] || kill -WINCH "$campaign_pid" 2>/dev/null || true' WINCH
trap 'exit 143' TERM
trap 'exit 129' HUP
