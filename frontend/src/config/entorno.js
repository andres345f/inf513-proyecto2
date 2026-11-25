// Valores que cambian entre desarrollo, prueba integrada y servidor (ver archivos .env.*).
export const entorno = {
  apiUrl: import.meta.env.VITE_API_URL || '/api',
  basename: import.meta.env.VITE_BASENAME || '/',
}
