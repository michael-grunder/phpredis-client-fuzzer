#!/bin/sh
# A stand-in for an `rr record` that dies without saying why: the trace is left
# unfinalised and stderr holds nothing to prove the recorder was at fault, so
# the run has to be treated as a failure whose artifacts cannot be replayed.
shift                                   # "record"
dir=${1#--output-trace-dir=}
shift
[ "$1" = "--chaos" ] && shift
mkdir -p "$dir"
: > "$dir/incomplete"
kill -ABRT $$
