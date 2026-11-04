/** Names declared documents while retaining historical project keys. */
export function hilosLegalDocumentLabel(document: string): string {
  switch (document) {
    case 'terms':
      return 'Terms of use'
    case 'privacy':
      return 'Privacy policy'
    default:
      return document
  }
}
