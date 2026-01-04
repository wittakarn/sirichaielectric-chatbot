/**
 * Product Fetcher Service
 *
 * This service fetches product information from your website/database
 * to keep the chatbot's knowledge always up-to-date.
 *
 * Benefits:
 * - Always reflects current inventory
 * - No need to update code when products change
 * - Can pull real pricing, stock levels, etc.
 */

export interface ProductFetcherConfig {
  websiteUrl: string;
  apiEndpoint?: string;
  updateIntervalMinutes?: number;
}

export class ProductFetcher {
  private config: ProductFetcherConfig;
  private productContext: string = '';
  private lastUpdate: Date | null = null;
  private updateInterval: NodeJS.Timeout | null = null;

  constructor(config: ProductFetcherConfig) {
    this.config = {
      websiteUrl: config.websiteUrl,
      apiEndpoint: config.apiEndpoint,
      updateIntervalMinutes: config.updateIntervalMinutes || 60, // Update every hour by default
    };
  }

  /**
   * Start automatic product data updates
   */
  async start(): Promise<void> {
    // Initial fetch
    await this.fetchProductData();

    // Set up periodic updates
    if (this.config.updateIntervalMinutes && this.config.updateIntervalMinutes > 0) {
      this.updateInterval = setInterval(
        () => this.fetchProductData(),
        this.config.updateIntervalMinutes * 60 * 1000
      );
      console.log(`[Product Fetcher] Auto-update enabled (every ${this.config.updateIntervalMinutes} minutes)`);
    }
  }

  /**
   * Stop automatic updates
   */
  stop(): void {
    if (this.updateInterval) {
      clearInterval(this.updateInterval);
      this.updateInterval = null;
    }
  }

  /**
   * Fetch product data from website/API
   *
   * Implementation options:
   * 1. Scrape your website HTML
   * 2. Call your e-commerce API
   * 3. Query your database directly
   * 4. Read from a product feed/CSV
   */
  async fetchProductData(): Promise<void> {
    try {
      console.log('[Product Fetcher] Fetching product data...');

      // Option 1: If you have a product API endpoint
      if (this.config.apiEndpoint) {
        await this.fetchFromAPI();
      }
      // Option 2: Scrape website (basic implementation)
      else {
        await this.fetchFromWebsite();
      }

      this.lastUpdate = new Date();
      console.log('[Product Fetcher] Product data updated successfully');
    } catch (error) {
      console.error('[Product Fetcher] Failed to fetch product data:', error);
      // Keep using old data if fetch fails
    }
  }

  /**
   * Fetch from API endpoint (recommended)
   */
  private async fetchFromAPI(): Promise<void> {
    if (!this.config.apiEndpoint) return;

    const response = await fetch(this.config.apiEndpoint);
    if (!response.ok) {
      throw new Error(`API returned ${response.status}`);
    }

    const data: any = await response.json();

    // Check for API error
    if (data.error) {
      throw new Error(data.message || 'API returned an error');
    }

    this.productContext = this.buildProductContext(data);
  }

  /**
   * Fetch from website (fallback)
   *
   * This is a placeholder - you'll need to customize based on your website structure
   */
  private async fetchFromWebsite(): Promise<void> {
    // For now, use basic company info
    // You can implement web scraping here using libraries like cheerio or puppeteer
    const basicInfo = {
      categories: [
        'Electrical Wires and Cables (Yazaki, Helukabel)',
        'Circuit Breakers and Contactors (Mitsubishi, Schneider, ABB)',
        'LED Lights and Fixtures (Philips, Panasonic)',
        'Cable Management Systems',
        'Solar and EV Charging Equipment',
        'Control Equipment and Switches',
      ],
      brands: ['Yazaki', 'Helukabel', 'Mitsubishi', 'Schneider Electric', 'ABB', 'Philips', 'Panasonic'],
    };

    this.productContext = this.buildProductContext(basicInfo);
  }

  /**
   * Build context string for the AI
   */
  private buildProductContext(data: any): string {
    // Format the data into a readable context for the AI
    let context = `CURRENT PRODUCT INVENTORY (Updated: ${data.lastUpdated || 'Recently'}):\n`;

    // Categories with counts and brands
    if (data.categories && data.categories.length > 0) {
      context += `\nหมวดหมู่สินค้า (Product Categories):\n`;
      data.categories.forEach((cat: any) => {
        context += `- ${cat.name}`;
        if (cat.brands && cat.brands.length > 0) {
          context += ` [${cat.brands.join(', ')}]`;
        }
        if (cat.productCount) {
          context += ` (${cat.productCount} สินค้า)`;
        }
        if (cat.priceRange) {
          context += ` - ราคา ${cat.priceRange}`;
        }
        context += `\n`;
      });
    }

    // All available brands
    if (data.brands && data.brands.length > 0) {
      context += `\nแบรนด์ที่มีจำหน่าย (Available Brands):\n`;
      context += data.brands.join(', ') + '\n';
    }

    // Featured/Popular products
    if (data.featuredProducts && data.featuredProducts.length > 0) {
      context += `\nสินค้าแนะนำ (Featured Products):\n`;
      data.featuredProducts.slice(0, 15).forEach((product: any) => {
        context += `- ${product.name}`;
        if (product.brand) {
          context += ` [${product.brand}]`;
        }
        if (product.category) {
          context += ` (${product.category})`;
        }
        if (product.price) {
          context += ` - ฿${product.price.toLocaleString('th-TH')}`;
        }
        if (product.inStock === false) {
          context += ` [สินค้าหมด]`;
        }
        context += `\n`;
      });
    }

    context += `\nWebsite: ${this.config.websiteUrl}\n`;

    return context;
  }

  /**
   * Get the current product context for AI prompts
   */
  getProductContext(): string {
    return this.productContext;
  }

  /**
   * Get last update timestamp
   */
  getLastUpdate(): Date | null {
    return this.lastUpdate;
  }

  /**
   * Manually trigger a product data refresh
   */
  async refresh(): Promise<void> {
    await this.fetchProductData();
  }
}
