declare namespace NodeJS {
  interface ProcessEnv {
    EXPO_PUBLIC_API_BASE_URL?: string;
    EXPO_PUBLIC_API_MODE?: 'production' | 'development';
  }
}

declare const process: {
  env: NodeJS.ProcessEnv;
};
