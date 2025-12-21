/**
 * LocalStorageService - Service for managing localStorage with cross-tab synchronization
 * 
 * Manages session and theme in localStorage and handles changes from other tabs
 * using the 'storage' event.
 */

export interface LocalStorageData {
  session: string | null
  theme: string | null
}

type StorageChangeCallback = (data: LocalStorageData) => void

export class LocalStorageService {
  private static readonly SESSION_KEY = 'session'
  private static readonly THEME_KEY = 'theme'
  
  private callbacks: Set<StorageChangeCallback> = new Set()
  private isInitialized = false

  /**
   * Initialize the service and set up cross-tab event listener
   */
  public init(): void {
    if (this.isInitialized) {
      return
    }

    // Listen for storage changes from other tabs
    window.addEventListener('storage', this.handleStorageChange.bind(this))
    this.isInitialized = true
  }

  /**
   * Cleanup - remove event listeners
   */
  public destroy(): void {
    if (!this.isInitialized) {
      return
    }

    window.removeEventListener('storage', this.handleStorageChange.bind(this))
    this.callbacks.clear()
    this.isInitialized = false
  }

  /**
   * Get current session token
   */
  public getSession(): string | null {
    return localStorage.getItem(LocalStorageService.SESSION_KEY)
  }

  /**
   * Get session token with initialization
   * If session doesn't exist, generates a new one and saves it
   * 
   * @returns Session token (always non-null)
   */
  public getSessionWithInit(): string {
    let session = this.getSession()
    
    if (!session) {
      // Generate new session token (32 hex characters = 16 bytes)
      const array = new Uint8Array(16)
      crypto.getRandomValues(array)
      session = Array.from(array, byte => byte.toString(16).padStart(2, '0')).join('')
      this.setSession(session)
    }
    
    return session
  }

  /**
   * Set session token
   */
  public setSession(session: string | null): void {
    if (session === null) {
      localStorage.removeItem(LocalStorageService.SESSION_KEY)
    } else {
      localStorage.setItem(LocalStorageService.SESSION_KEY, session)
    }
    
    // Notify callbacks about the change (same-tab update)
    this.notifyCallbacks(this.getData())
  }

  /**
   * Get current theme
   */
  public getTheme(): string | null {
    return localStorage.getItem(LocalStorageService.THEME_KEY)
  }

  /**
   * Set theme
   */
  public setTheme(theme: string | null): void {
    if (theme === null) {
      localStorage.removeItem(LocalStorageService.THEME_KEY)
    } else {
      localStorage.setItem(LocalStorageService.THEME_KEY, theme)
    }
    
    // Notify callbacks about the change (same-tab update)
    this.notifyCallbacks(this.getData())
  }

  /**
   * Get all data
   */
  public getData(): LocalStorageData {
    return {
      session: this.getSession(),
      theme: this.getTheme(),
    }
  }

  /**
   * Set all data at once
   */
  public setData(data: Partial<LocalStorageData>): void {
    if (data.session !== undefined) {
      this.setSession(data.session)
    }
    if (data.theme !== undefined) {
      this.setTheme(data.theme)
    }
  }

  /**
   * Clear all data
   */
  public clear(): void {
    localStorage.removeItem(LocalStorageService.SESSION_KEY)
    localStorage.removeItem(LocalStorageService.THEME_KEY)
    this.notifyCallbacks(this.getData())
  }

  /**
   * Subscribe to storage changes (including cross-tab changes)
   */
  public onChange(callback: StorageChangeCallback): () => void {
    this.callbacks.add(callback)
    
    // Return unsubscribe function
    return () => {
      this.callbacks.delete(callback)
    }
  }

  /**
   * Handle storage event from other tabs
   */
  private handleStorageChange(event: StorageEvent): void {
    // Only handle changes to our keys
    if (event.key !== LocalStorageService.SESSION_KEY && event.key !== LocalStorageService.THEME_KEY) {
      return
    }

    // If session changed, this is critical - notify immediately
    if (event.key === LocalStorageService.SESSION_KEY) {
      console.warn('Session changed in another tab!', {
        oldValue: event.oldValue,
        newValue: event.newValue,
      })
    }

    // Notify all callbacks about the change
    this.notifyCallbacks(this.getData())
  }

  /**
   * Notify all callbacks about data change
   */
  private notifyCallbacks(data: LocalStorageData): void {
    this.callbacks.forEach(callback => {
      try {
        callback(data)
      } catch (error) {
        console.error('Error in storage change callback:', error)
      }
    })
  }
}

// Export singleton instance
export const localStorageService = new LocalStorageService()

