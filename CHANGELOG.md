# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- Add opt-in `cache-hll` and `cache-collections` scenarios for F01/F02: bounded
  HLL and collection warming/mutation fixtures, server count parity, Relay cache
  counter observations, and server-free regression tests.
- Add a source-backed Relay coverage opportunity report with prioritized test
  scenarios and a plan for measuring incremental coverage.
- add a changelog
- Add a local coverage stress-launch script with explicit inferior PHP selection
  and reference settings for the standalone evilresp proxy.

### Fixed

- Clarify worker PHP selection for coverage builds and correct the local coverage
  INI's extension paths and eviction-policy choices.
