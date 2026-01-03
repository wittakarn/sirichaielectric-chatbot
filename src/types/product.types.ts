export interface Product {
  id: string;
  name: string;
  nameTh: string;
  category: string;
  brand: string;
  description: string;
  descriptionTh: string;
  specifications?: Record<string, string>;
  applications?: string[];
}

export interface ProductCategory {
  id: string;
  name: string;
  nameTh: string;
  description: string;
  descriptionTh: string;
  brands: string[];
  keywords: string[];
}
