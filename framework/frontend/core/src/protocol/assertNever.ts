/**
 * Compile-time exhaustiveness guard for discriminated-union narrowing.
 *
 * Call it in the `default`/final branch of a union switch: the call only
 * typechecks when every member has been narrowed away, and it throws if an
 * unexpected value still reaches it at runtime.
 */
export function assertNever(value: never): never {
  throw new Error(
    `Unhandled discriminated-union member: ${JSON.stringify(value)}`,
  )
}
