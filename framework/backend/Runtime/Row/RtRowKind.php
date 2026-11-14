<?php

declare(strict_types=1);

namespace Hilos\Runtime\Row;

/** Internal segmented value and page kinds. */
enum RtRowKind
{
    case Scalar;
    case Text;
    case Map;
    case Bytes;
    case Entry;
    case Identity;
    case Record;
    case IndexBranch;
    case IndexLeaf;
    case Membership;
    case Path;
    case Version;
    case Change;
    case Mutation;
    case MutationDetails;
}
