import { useContext } from 'react'
import { NavegacionContext } from '../context/contextos'

export function useNavegacion() {
  const contexto = useContext(NavegacionContext)
  if (!contexto) throw new Error('useNavegacion debe usarse dentro de NavegacionProvider')
  return contexto
}
