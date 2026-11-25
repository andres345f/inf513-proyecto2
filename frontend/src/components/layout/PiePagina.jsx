import { useLocation } from 'react-router-dom'
import MarcaFeder from '../ui/MarcaFeder'
import { Eye } from 'lucide-react'
import { useContadorVisitas } from '../../hooks/useContadorVisitas'
import { useNavegacion } from '../../hooks/useNavegacion'
import { useSesion } from '../../hooks/useSesion'
import { formatearNumero } from '../../utils/formato'
import './PiePagina.css'

const ANIO_ACTUAL = new Date().getFullYear()

/** Pie de página con el contador persistente de visitas de la página actual. */
export default function PiePagina({ inerte }) {
  const { pathname } = useLocation()
  const { cargando: cargandoSesion } = useSesion()
  const { cargando: cargandoMenu } = useNavegacion()
  // Se cuenta solo cuando la vista quedó resuelta (sin redirección pendiente ni dirección inexistente).
  const { total: visitas, contable } = useContadorVisitas(pathname, !cargandoSesion && !cargandoMenu)

  return (
    <footer className="pie" inert={inerte || undefined}>
      <div className="pie__marca"><MarcaFeder /></div>
      <p className="pie__contador">
        <Eye size={16} aria-hidden="true" />
        {!contable
          ? 'Esta página no se cuenta como visita.'
          : visitas === null
          ? 'Contando visitas…'
          : `Esta página fue visitada ${formatearNumero(visitas)} ${visitas === 1 ? 'vez' : 'veces'}.`}
      </p>
      <p className="pie__creditos">
        FICCT - UAGRM · {ANIO_ACTUAL}
      </p>
    </footer>
  )
}
