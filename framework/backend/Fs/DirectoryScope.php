<?php

declare(strict_types=1);

namespace Hilos\Fs;

/**
 * DirectoryScope - whose a $fs directory is: its node's or the whole cluster's (HIL-1240).
 *
 * The test is one question: will a process of another node open a file from this directory?
 * Then it is the cluster's. Kept apart from AgentScope, which counts the instances of an agent,
 * not directories.
 *
 * There is no default, on purpose (the owner's word, 28.09.2026: "let it be stated explicitly"):
 * a node directory by default silently loses files when an agent moves to another node, and a
 * cluster directory by default demands a shared volume even for a node's scratch space. The
 * whole rule lives in docs/agents/architecture/filesystem.md.
 */
enum DirectoryScope
{
    /** One directory for the cluster: every node and its nginx see the same files. */
    case CLUSTER;

    /** One directory per node: only the processes of that node open its files. */
    case NODE;
}
