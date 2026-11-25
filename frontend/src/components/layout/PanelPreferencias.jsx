import { useCallback, useRef, useState } from 'react'
import { SlidersHorizontal } from 'lucide-react'
import { ESCALAS, MODOS, TEMAS } from '../../config/preferencias'
import { useClicFuera } from '../../hooks/useClicFuera'
import { usePreferencias } from '../../hooks/usePreferencias'
import './PanelPreferencias.css'

/** Tema por edad, modo día/noche, tamaño de letra y alto contraste. */
export default function PanelPreferencias() {
  const [abierto, setAbierto] = useState(false)
  const contenedor = useRef(null)
  const boton = useRef(null)
  const { tema, modo, modoEfectivo, contraste, escala, actualizar, cambiarEscala, restablecer } = usePreferencias()

  const cerrar = useCallback(() => setAbierto(false), [])
  useClicFuera(contenedor, abierto, cerrar)

  return (
    <div className="preferencias" ref={contenedor} onKeyDown={(e) => {
      if (e.key === 'Escape' && abierto) { e.preventDefault(); e.stopPropagation(); cerrar(); boton.current?.focus() }
    }}>
      <button
        ref={boton}
        type="button"
        className="preferencias__boton"
        aria-expanded={abierto}
        aria-controls="panel-preferencias"
        onClick={() => setAbierto((valor) => !valor)}
      >
        <SlidersHorizontal size={18} aria-hidden="true" />
        <span className="preferencias__texto-boton">Apariencia</span>
      </button>

      {abierto && (
        <div id="panel-preferencias" className="preferencias__panel" role="dialog" aria-label="Apariencia y accesibilidad">
          <fieldset className="preferencias__grupo">
            <legend>Tema</legend>
            <div className="preferencias__opciones">
              {TEMAS.map((opcion) => (
                <button key={opcion.valor} type="button" aria-pressed={tema === opcion.valor}
                  className="preferencias__opcion" onClick={() => actualizar({ tema: opcion.valor })}>
                  {opcion.etiqueta}
                </button>
              ))}
            </div>
          </fieldset>

          <fieldset className="preferencias__grupo">
            <legend>Modo</legend>
            <div className="preferencias__opciones">
              {MODOS.map((opcion) => (
                <button key={opcion.valor} type="button" aria-pressed={modo === opcion.valor}
                  className="preferencias__opcion" onClick={() => actualizar({ modo: opcion.valor })}>
                  {opcion.etiqueta}
                </button>
              ))}
            </div>
            {modo === 'auto' && (
              <p className="preferencias__nota">
                Ahora: {modoEfectivo === 'noche' ? 'noche' : 'día'} (noche de 18:00 a 06:00).
              </p>
            )}
          </fieldset>

          <fieldset className="preferencias__grupo">
            <legend>Tamaño de letra</legend>
            <div className="preferencias__escala">
              <button type="button" className="preferencias__opcion" onClick={() => cambiarEscala(-1)}
                disabled={escala === ESCALAS[0]} aria-label="Reducir tamaño de letra">A−</button>
              <output className="preferencias__valor" aria-live="polite">{escala} %</output>
              <button type="button" className="preferencias__opcion" onClick={() => cambiarEscala(1)}
                disabled={escala === ESCALAS[ESCALAS.length - 1]} aria-label="Aumentar tamaño de letra">A+</button>
            </div>
          </fieldset>

          <fieldset className="preferencias__grupo">
            <legend>Contraste</legend>
            <label className="preferencias__interruptor">
              <input type="checkbox" checked={contraste === 'alto'}
                onChange={(evento) => actualizar({ contraste: evento.target.checked ? 'alto' : 'normal' })} />
              <span>Alto contraste</span>
            </label>
          </fieldset>

          <button type="button" className="preferencias__restablecer" onClick={restablecer}>
            Restablecer apariencia
          </button>
        </div>
      )}
    </div>
  )
}
