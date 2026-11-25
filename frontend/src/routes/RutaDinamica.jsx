import { useLocation } from 'react-router-dom'
import Cargando from '../components/ui/Cargando'
import { useNavegacion } from '../hooks/useNavegacion'
import NoEncontradoPagina from '../pages/estado/NoEncontradoPagina'
import ProximamentePagina from '../pages/estado/ProximamentePagina'

/**
 * Rutas del menú que todavía no tienen pantalla propia: si la ruta está en el menú del rol activo,
 * se muestra "en construcción"; si no, la página no existe para este usuario.
 */
export default function RutaDinamica() {
  const { pathname } = useLocation()
  const { cargando, entradaDeRuta } = useNavegacion()

  if (cargando) return <Cargando />

  const entrada = entradaDeRuta(pathname)
  return entrada ? <ProximamentePagina seccion={entrada.etiqueta} /> : <NoEncontradoPagina />
}
