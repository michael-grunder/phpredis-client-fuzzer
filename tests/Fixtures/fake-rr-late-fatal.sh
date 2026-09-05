#!/bin/sh
# A stand-in for an `rr record` that finalises its trace and *then* dies of its
# own fault. The trace looks fine, but the SIGABRT the harness sees belongs to
# rr, not to the client under test.
shift                                   # "record"
dir=${1#--output-trace-dir=}
shift
[ "$1" = "--chaos" ] && shift
mkdir -p "$dir"
: > "$dir/incomplete"
"$@"
rm -f "$dir/incomplete"
echo "=== Start rr backtrace:" >&2
echo "/usr/local/bin/rr(+0xda34a) [0x55edb5d1534a]" >&2
echo "=== End rr backtrace" >&2
kill -ABRT $$
