import PaginaListado from './PaginaListado'
import BarraResumen from './BarraResumen'

/** Variante del listado común para paneles con conteos y filtros por estado. */
export default function PlantillaLista({ titulo, explicacion, accionPrincipal, resumen, filtros, vacio, cargando, error, paginacion, className, children }) {
  return (
    <PaginaListado titulo={titulo} descripcion={explicacion} accionPrincipal={accionPrincipal}
      resumen={resumen && <BarraResumen {...resumen} />} filtros={filtros}
      filtrosActivos={resumen?.chips?.filter((chip) => chip.activo).length}
      vacio={vacio} cargando={cargando} error={error} paginacion={paginacion} className={className}>
      {children}
    </PaginaListado>
  )
}
