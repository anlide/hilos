import { parsePhoneNumberFromString } from 'libphonenumber-js/min'

/**
 * Present a canonical phone identifier in its international groups. Parsing
 * only changes the caption; an unrecognized identifier is kept as supplied.
 * Internal adapter, deliberately absent from the base and root barrels.
 *
 * @param identifier The stored phone identifier, normally E.164.
 */
export function formatPhoneNumber(identifier: string): string {
  return (
    parsePhoneNumberFromString(identifier)?.formatInternational() ?? identifier
  )
}
