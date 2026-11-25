import { createContext } from 'react'

// Objetos de contexto separados de sus proveedores (requisito de Fast Refresh de Vite).
export const SesionContext = createContext(null)
export const NavegacionContext = createContext(null)
export const PreferenciasContext = createContext(null)
