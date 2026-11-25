import './BarraResumen.css'

/**
 * Resumen de resultados de un listado: total + fichas de estado que filtran.
 * chips: [{ etiqueta, cantidad, tono = 'info', activo, alHacerClic }].
 * Un chip con alHacerClic es un botón de filtro; sin él, solo una cuenta.
 */
export default function BarraResumen({ etiquetaTotal = 'resultados', total = 0, chips = [] }) {
  return (
    <div className="barra-resumen">
      <span className="barra-resumen__total" aria-live="polite">
        {total} {etiquetaTotal}
      </span>

      {chips.length > 0 && (
        <ul className="barra-resumen__chips">
          {chips.map((chip) => {
            const clases = ['barra-resumen__chip', `barra-resumen__chip--${chip.tono || 'info'}`,
              chip.activo && 'barra-resumen__chip--activo'].filter(Boolean).join(' ')
            const contenido = (
              <>
                <span className="barra-resumen__punto" aria-hidden="true" />
                {chip.etiqueta}
                <strong className="barra-resumen__cantidad">{chip.cantidad ?? chip.valor}</strong>
              </>
            )

            return (
              <li key={chip.etiqueta}>
                {chip.alHacerClic ? (
                  <button type="button" className={clases} aria-pressed={Boolean(chip.activo)}
                    onClick={chip.alHacerClic}>
                    {contenido}
                  </button>
                ) : (
                  <span className={clases}>{contenido}</span>
                )}
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}
