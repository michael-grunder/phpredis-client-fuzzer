#!/bin/sh
# A stand-in for `rr record` that finalises its trace: it creates the trace
# directory with the `incomplete` sentinel, runs the child, then clears the
# sentinel the way a successful recording does.
shift                                   # "record"
dir=${1#--output-trace-dir=}
shift
[ "$1" = "--chaos" ] && shift
mkdir -p "$dir"
: > "$dir/incomplete"
"$@"
status=$?
rm -f "$dir/incomplete"
exit $status
