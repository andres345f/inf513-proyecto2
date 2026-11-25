import { useId, useState } from 'react'
import { Eraser, SlidersHorizontal } from 'lucide-react'
import './FiltrosBarra.css'

export default function FiltrosBarra({ etiqueta = 'Filtrar resultados', activos = 0, alLimpiar = null, className = '', children }) {
  const [abierto, setAbierto] = useState(false)
  const controles = useId()
  return (
    <div className="filtros-adaptables">
      <button type="button" className="filtros-adaptables__abrir" aria-controls={controles} aria-expanded={abierto}
        onClick={() => setAbierto(!abierto)}>
        <SlidersHorizontal size={18} aria-hidden="true" /> {etiqueta}{activos > 0 && ` (${activos})`}
      </button>
      <form id={controles} className={`filtros-barra ${className}`.trim()} data-abierto={abierto} role="search"
        aria-label={etiqueta} onSubmit={(e) => e.preventDefault()} noValidate>
        <div className="filtros-barra__campos">{children}</div>
        {alLimpiar && <button type="button" className="filtros-barra__limpiar" onClick={alLimpiar}>
          <Eraser size={15} aria-hidden="true" /> Limpiar
        </button>}
      </form>
    </div>
  )
}
