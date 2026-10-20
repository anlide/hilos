/** Names declared documents while retaining historical project keys. */
export function hilosLegalDocumentLabel(document: string): string {
  switch (document) {
    case 'terms':
      return 'Terms'
    case 'privacy':
      return 'Privacy policy'
    default:
      return document
  }
}
