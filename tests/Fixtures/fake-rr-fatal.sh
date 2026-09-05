#!/bin/sh
# A stand-in for an `rr record` that dies before it can record anything, the
# way rr does when a signal stops the tracee inside Task::spawn(). It leaves an
# unfinalised trace directory behind and aborts, so the harness sees a SIGABRT
# that has nothing to do with the client under test.
shift                                   # "record"
dir=${1#--output-trace-dir=}
shift
[ "$1" = "--chaos" ] && shift
mkdir -p "$dir"
: > "$dir/incomplete"
echo "[FATAL src/Task.cc:3887:spawn()] Unexpected stop 0x1c7f (STOP-SIGWINCH)" >&2
kill -ABRT $$
