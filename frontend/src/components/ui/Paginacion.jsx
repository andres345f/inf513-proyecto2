import { ChevronLeft, ChevronRight } from 'lucide-react'
import './Paginacion.css'

/** Paginación del lado del servidor. */
export default function Paginacion({ pagina, paginas, alCambiar }) {
  if (!paginas || paginas <= 1) return null

  const destinos = [...new Set([1, pagina - 1, pagina, pagina + 1, paginas])].filter((n) => n > 0 && n <= paginas).sort((a, b) => a - b)

  return (
    <nav className="paginacion" aria-label="Paginación">
      <button type="button" className="paginacion__boton" disabled={pagina <= 1} onClick={() => alCambiar(pagina - 1)}>
        <ChevronLeft size={16} aria-hidden="true" /> Anterior
      </button>
      <div className="paginacion__numeros">{destinos.map((n) => (
        <button type="button" key={n} className="paginacion__boton" aria-label={`Página ${n}`}
          aria-current={n === pagina ? 'page' : undefined} onClick={() => n !== pagina && alCambiar(n)}>{n}</button>
      ))}</div>
      <span className="paginacion__estado" aria-live="polite">Página {pagina} de {paginas}</span>
      <button type="button" className="paginacion__boton" disabled={pagina >= paginas} onClick={() => alCambiar(pagina + 1)}>
        Siguiente <ChevronRight size={16} aria-hidden="true" />
      </button>
    </nav>
  )
}
